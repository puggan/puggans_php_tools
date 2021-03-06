<?php

	/**
	* Puggans SMTP/email-class
	* Allows easy sending of emails, both plain text, HTML and
	* mixed messages with alternate content and attachments.
	* can also sign both text/plain and multipart/alternative
	* encryption also works
	* missing email-parsing, verify and decrypt
	*
	* @url https://github.com/puggan/puggans_php_tools/blob/master/smtp.php
	**/
	class smtp
	{
		public $hostname;
		public $default_from;
		public $default_charset;

		public $always_base64_encode_html = FALSE;
		public $always_base64_encode_text = FALSE;
		public $last_boundary = NULL;

		// create an instace of this class, and set some default values.
		public function __construct($hostname = NULL, $default_from = 'noreply', $default_charset = 'ISO-8859-1')
		{
			// Guess the hostname if missing
			if(!$hostname AND isset($_SERVER['HTTP_HOST'])) $hostname = $_SERVER['HTTP_HOST'];
			if(!$hostname) $hostname = gethostname();
			if(!$hostname) $hostname = 'localhost';

			// save default variables
			$this->hostname = $hostname;
			$this->default_from = $default_from;
			$this->default_charset = $default_charset;
		}

		/**
		* Creates a message with attachments and sends it as plain text.
		**/
		public function send_plain($subject, $body, $to, $from = NULL, $attachments = NULL, $charset = NULL, $extra_headers = NULL, $private_key = NULL, $public_key = NULL)
		{
			// just wrap the send-function, ignoreing the html-parameter, compatible with our old code.
			return $this->send($subject, NULL, $body, $to, $from, $attachments, $charset, $extra_headers, $private_key, $public_key);
		}

		/**
		* Creates a message with attachments and sends it as HTML.
		**/
		public function send_html($subject, $body, $to, $from = NULL, $attachments = NULL, $charset = NULL, $extra_headers = NULL, $private_key = NULL, $public_key = NULL)
		{
			// just wrap the send-function, ignoreing the text-parameter, compatible with our old code.
			return $this->send($subject, $body, NULL, $to, $from, $attachments, $charset, $extra_headers, $extra_headers,$private_key, $public_key);
		}

		// Create an email, and send it
		public function send($subject, $html_body, $text_body, $to, $from = NULL, $attachments = NULL, $charset = NULL, $extra_headers = NULL, $private_key = NULL, $public_key = NULL)
		{
			// wrap the create email function, to make the email
			$parts = $this->create_email($subject, $html_body, $text_body, $to, $from, $attachments, $charset, $extra_headers, $private_key, $public_key);

			// if successyfully created
			if($parts)
			{
				/* Prevent duplicate to-fields */
				unset($parts['headers']['To']);
				/* Send the actual message. */
				return mail($parts['to'], $parts['subject'], $parts['body'], $this->flaten_headers($parts['headers']));
			}
			else
			{
				return FALSE;
			}
		}

		// Create an email, and send it using smtp-socket
		public function send_smtp($server, $subject, $html_body, $text_body, $to, $from = NULL, $attachments = NULL, $charset = NULL, $extra_headers = NULL, $private_key = NULL, $public_key = NULL)
		{
			// wrap the create email function, to make the email
			$parts = $this->create_email($subject, $html_body, $text_body, $to, $from, $attachments, $charset, $extra_headers, $private_key, $public_key);

			// if successyfully created
			if($parts)
			{
				//FIXME: socket connect to server
				/* Send the actual message. */
			}
			else
			{
				return FALSE;
			}
		}

		/**
		* Create a mime-type email from given parts/parameters
		* @param $subject email subject
		* @param $html_body html-part of the message
		* @param $text_body text-part of the message
		* @param $to recivers of the message
		* @param $from sender (optionaly, NULL => use default values from __construct() )
		* @param $attachments file attachments, list of filenames, or list of array(file_mime_type, file_name, data)
		* @param $charset should the outgoinf email be ISO-8859-1 or UTF-8? default in __construct is ISO-8859-1
		* @param $extra_headers add some extra headers to the email? or replace some of the default, maybe you want another Reply-To?
		* @param $private_key sign your message using this key, see http://php.net/manual/en/function.gnupg-import.php
		* @param $public_key encrypt your message using this key, see http://php.net/manual/en/function.gnupg-import.php
		* @return array($from, $to, $subject, $content_type, $headers, $body)
		**/
		public function create_email($subject, $html_body, $text_body, $to, $from = NULL, $attachments = NULL, $charset = NULL, $extra_headers = NULL, $private_key = NULL, $public_key = NULL, $text_mime_type = NULL, $html_mime_type = NULL)
		{
			// set default vars if missing
			if(!$charset) $charset = $this->default_charset;
			if(!$from) $from = $this->default_from;

			// if from only contains a username, append default hostname
			if(!strstr($from, '@')) $from .= '@' . $this->hostname;

			if(!$text_mime_type) $text_mime_type = "text/plain; charset={$charset}";
			if(!$html_mime_type) $html_mime_type = "text/html; charset={$charset}";

			// remeber what encoding we had, in case we need to change it
			$old_internal_encoding = mb_internal_encoding();

			// if we have a html_body
			if($html_body)
			{
				// if html is a multipart/related
				if(is_array($html_body))
				{
					// rename body as parts
					$html_parts = $html_body;

					// move first part to body
					$html_body = array_shift($html_parts);
				}
				else
				{
					// set no parts
					$html_parts = NULL;
				}

				// check what encoding it has.
				$encoding = mb_detect_encoding($html_body, 'ASCII, UTF-8, ISO-8859-1', TRUE);

				// convert it to the wanted encoding
				$html_body = mb_convert_encoding($html_body, $charset, $encoding);

				// if the html is incomplete
				if(!stristr($html_body, '<html'))
				{
					// wrap it in a html and body tag
					$html_body = <<<HTML_BLOCK
<html>
	<head>
		<title>{$subject}</title>
		<meta http-equiv="content-type" content="text/html; charset={$charset}" />
	</head>
	<body>
{$html_body}
	</body>
</html>
HTML_BLOCK;
				}

				// Create a list of headers for the html_part
				$html_headers = array();
				$html_headers['Content-Type'] = "Content-Type: {$html_mime_type}";
				$html_headers['Content-Transfer-Encoding'] = "Content-Transfer-Encoding: 8bit";

				// if we want html base64_encoded
				if($this->always_base64_encode_html OR $private_key)
				{
					// add base64 encodign to avoid manipulation by MDA and MTA:s
					$html_headers['Content-Transfer-Encoding'] = "Content-Transfer-Encoding: base64";
					$html_body = chunk_split(base64_encode($html_body));
				}

				// if html is a multipart/related
				if($html_parts)
				{
					// Create an empty list of mime-parts
					$parts = array();

					// add current part as first part
					$parts[] = $this->flaten_email($html_headers, $html_body);

					// Iterate over each attachment/html-part.
					foreach((array) $html_parts as $row_id => $attachment)
					{
						// add cid if missing
						if(is_array($attachment))
						{
							if(!isset($attachment['cid']))
							{
								$attachment['cid'] = $row_id;
							}

							// add as mime-part
							$parts[] = $this->flatten_attachments($attachment);
						}
						else
						{
							// add as mime-part, prepend CID
							$parts[] = "Content-ID: <{$row_id}>\n" . $this->flatten_attachments($attachment);
						}
					}

					// merge mime-parts into a multipart/related
					$parts = $this->mime_encode('multipart/related', $parts, TRUE);

					// set the multipart/related as the current part
					$html_mime_type = "multipart/related";
					$html_headers = $parts['headers'];
					$html_body = $parts['body'];
					unset($parts);
				}
			}

			// if we have a text_body
			if($text_body)
			{
				// check what encoding it has.
				$encoding_text = mb_detect_encoding($text_body, 'ASCII, UTF-8, ISO-8859-1', TRUE);

				// convert it to the wanted encoding
				$text_body = mb_convert_encoding($text_body, $charset, $encoding_text);
			}

			/* if subject is none ascii, mime encode it */
			if(!mb_detect_encoding($subject, "ASCII", TRUE))
			{
				// check what encoding it has.
				$subject_encoding = mb_detect_encoding($subject, 'ASCII, UTF-8, ISO-8859-1', TRUE);

				// set current encoding to same as subject (mb_encode_mimeheader only workes with current encoding)
				mb_internal_encoding($subject_encoding);

				// mime-encode subject
				$subject = mb_encode_mimeheader($subject, $subject_encoding, 'Q');

				// restore current encoding
				mb_internal_encoding($old_internal_encoding);
			}

			// check if none-ascii in from-field
			if(!mb_detect_encoding($from, "ASCII", TRUE))
			{
				// split name and email
				$from_parts = explode("<", $from, 2);

				// if successyfully splited in name and email
				if(count($from_parts) == 2)
				{
					// check what encoding it has.
					$from_encoding = mb_detect_encoding($from, 'ASCII, UTF-8, ISO-8859-1', TRUE);

					// set current encoding to same as from (mb_encode_mimeheader only workes with current encoding)
					mb_internal_encoding($from_encoding);

					// mime-encode from-name
					$from = mb_encode_mimeheader($from_parts[0], $from_encoding, 'Q') . "<" . $from_parts[1];

					// restore current encoding
					mb_internal_encoding($old_internal_encoding);
				}
			}

			// FIXME: allow multiple adresses, in both array and string form, and encode them correctly, currently only the first name gets encoded.
			// check if none-ascii in to-field
			if(!mb_detect_encoding($to, "ASCII", TRUE))
			{
				// split name and email
				$to_parts = explode("<", $to, 2);

				// if successyfully splited in name and email
				if(count($to_parts) == 2)
				{
					// check what encoding it has.
					$to_encoding = mb_detect_encoding($to, 'ASCII, UTF-8, ISO-8859-1', TRUE);

					// set current encoding to same as to (mb_encode_mimeheader only workes with current encoding)
					mb_internal_encoding($to_encoding);

					// mime-encode to-name
					$to = mb_encode_mimeheader($to_parts[0], $to_encoding, 'Q') . "<" . $to_parts[1];

					// restore current encoding
					mb_internal_encoding($old_internal_encoding);
				}
			}

			// create a empty list of envelope headers
			$base_headers = array();

			// create a empty list of headers for the current part
			$part_headers = array();

			// create a empty body for the current part
			$email_data = "";

			// make sure $extra_headers is an array, to avoid error messages, useful when $extra_headers is NULL
			if(!is_array($extra_headers)) $extra_headers = array();

			/* Set the mail client field, try to use headers from $extra_headers if exists */
			$base_headers['X-Mailer'] = (isset($extra_headers['X-Mailer']) ? $extra_headers['X-Mailer'] : "X-Mailer: Puggan-smtp v0.1");
			$base_headers['Date'] = (isset($extra_headers['Date']) ? $extra_headers['Date'] : "Date: " . date("r"));
			$base_headers['Message-ID'] = (isset($extra_headers['Message-ID']) ? $extra_headers['Message-ID'] : "Message-ID: <" . $this->generate_hash() . "@{$this->hostname}>");
			$base_headers['Subject'] = (isset($extra_headers['Subject']) ? $extra_headers['Subject'] : "Subject: {$subject}");
			$base_headers['Sender'] = (isset($extra_headers['Sender']) ? $extra_headers['Sender'] : "Sender: {$from}");
			$base_headers['From'] = (isset($extra_headers['From']) ? $extra_headers['From'] : "From: {$from}");
			$base_headers['Reply-To'] = (isset($extra_headers['Reply-To']) ? $extra_headers['Reply-To'] : "Reply-To: {$from}");
			$base_headers['To'] = (isset($extra_headers['To']) ? $extra_headers['To'] : "To: {$to}");

			// append the rest of the headers from $extra_headers
			$base_headers += $extra_headers;

			// define the first current part as plain text
			$content_type = $text_mime_type;
			$part_headers = array();
			$part_headers['Content-Type'] = "Content-Type: {$content_type}";
			$part_headers['Content-Transfer-Encoding'] = "Content-Transfer-Encoding: 8bit";

			// if we want text base64_encoded
			if($this->always_base64_encode_text AND $content_type == "text/plain")
			{
				// add base64 encodign to avoid manipulation by MDA and MTA:s
				$part_headers['Content-Transfer-Encoding'] = "Content-Transfer-Encoding: base64";
				$email_data = chunk_split(base64_encode($text_body));
			}
			else
			{
				// set text_body as current body
				$email_data = $text_body;
			}

			// if we have a html_body
			if($html_body)
			{
				// if html isn't the only part
				if($email_data)
				{
					// create an empty list of mime-parts
					$parts = array();

					// if current part is text/plain, put html first
					if($content_type == "text/plain")
					{
						// add the html part
						$parts['html'] = $this->flaten_email($html_headers, $html_body);

						// add the current part
						$parts['txt'] = $this->flaten_email($part_headers, $email_data);
					}
					// else put html last
					else
					{
						// add the current part
						$parts['txt'] = $this->flaten_email($part_headers, $email_data);

						// add the html part
						$parts['html'] = $this->flaten_email($html_headers, $html_body);
					}

					// merge part in to a multipart/alternative
					$parts = $this->mime_encode('multipart/alternative', $parts, TRUE);

					// set the multipart/alternative as the current part
					$content_type = "multipart/alternative";
					$part_headers = $parts['headers'];
					$email_data = $parts['body'];
					unset($parts);
				}
				else
				{
					// set html as the current part;
					$email_data = $html_body;
					$content_type = "text/html";
					$part_headers['Content-Type'] = "Content-Type: {$content_type}";
				}
			}

			// if asked to add attachments
			if($attachments)
			{
				// Create an empty list of mime-parts
				$parts = array();

				// add current part as first part
				$parts[] = $this->flaten_email($part_headers, $email_data);

				// Iterate over each attachment.
				foreach((array) $attachments as $row_id => $attachment)
				{
					$parts[] = $this->flatten_attachments($attachment);
				}

				// merge mime-parts into a multipart/mixed
				$parts = $this->mime_encode('multipart/mixed', $parts, TRUE);

				// set the multipart/mixed as the current part
				$content_type = "multipart/mixed";
				$part_headers = $parts['headers'];
				$email_data = $parts['body'];
				unset($parts);
			}

			// if we have a private_key for signing
			if($private_key)
			{
				// make sure we have the tools installed
				if(!class_exists("gnupg"))
				{
					// abort if tools missing
					trigger_error("Missing package: dev-php/pecl-gnupg");
					return FALSE;
				}

				// create an instace of the gpg-tool
				$gpg = new gnupg();

				// is the $private_key a file?
				if(file_exists($private_key))
				{
					// import that file as a key
					$key_info = $gpg->import(file_get_contents($private_key));
				}

				// or is it just a string
				else
				{
					// import that string as a key
					$key_info = $gpg->import($private_key);
				}

				// did we fail the importing?
				if(!$key_info)
				{
					// abort
					trigger_error("Failed to load private key");
					return FALSE;
				}

				// mark the imported key as current sign key
				$result = $gpg->addsignkey($key_info["fingerprint"]);

				// failed to select key
				if(!$result)
				{
					// abort
					trigger_error("Failed selecting private key");
					return FALSE;
				}

				// set signing mode to detach, (don't include message in signature file)
				$gpg->setsignmode(GNUPG_SIG_MODE_DETACH);

				// create an empty list of signature parts
				$signature_parts = array();

				// add the current part as the raw-part
				$signature_parts['raw'] = $this->flaten_email($part_headers, $email_data);

				// sign the raw part
				$signature = $gpg->sign($signature_parts['raw']);

				// create headers for signature
				$signature_headers = array();
				$signature_headers['Content-Type'] = 'Content-Type: application/pgp-signature; name="signature.asc"';
				$signature_headers['Content-Description'] = 'Content-Description: This is a digitally signed message part.';
				$signature_headers['Content-Transfer-Encoding'] = 'Content-Transfer-Encoding: 7Bit';

				// add signature (with headers) as a mime-part
				$signature_parts['signature'] = $this->flaten_email($signature_headers, $signature);

				// merge parts to a multipart/signed
				$parts = $this->mime_encode('multipart/signed; micalg="pgp-sha1"; protocol="application/pgp-signature"', $signature_parts, TRUE);

				// set the multipart/signed as current part
				$content_type = "multipart/signed";
				$part_headers = $parts['headers'];
				$email_data = $parts['body'];
				unset($parts);
			}

			// do we have a public key for encryption?
			if($public_key)
			{
				// make sure we have the tools installed
				if(!class_exists("gnupg"))
				{
					// abort if tools missing
					trigger_error("Missing package: dev-php/pecl-gnupg");
					return FALSE;
				}

				// create an instace of the gpg-tool
				$gpg = new gnupg();

				// is the $public_key a file?
				if(file_exists($public_key))
				{
					// import that file as a key
					$key_info = $gpg->import(file_get_contents($public_key));
				}

				// or is it just a string
				else
				{
					// import that string as a key
					$key_info = $gpg->import($public_key);
				}

				// did we fail the importing?
				if(!$key_info)
				{
					// abort
					trigger_error("Failed to load private key");
					return FALSE;
				}

				// mark the imported key as current encrypt key
				$result = $gpg->addencryptkey($key_info["fingerprint"]);

				// encrypt the curren mime-part
				$encryption = $gpg->encrypt($this->flaten_email($part_headers, $email_data));

				// create a list of mime-parts
				$parts = array();

				// create default encryption header for part 1 (meta)
				$encryption_headers = array();
				$encryption_headers[] = 'Content-Type: application/pgp-encrypted';
				$encryption_headers[] = 'Content-Disposition: attachment';
				$encryption_headers[] = 'Content-Transfer-Encoding: 7Bit';

				// make mime-part of headers and version info
				$parts['meta'] = $this->flaten_email($encryption_headers, "Version: 1");

				// create deafult encryption headers for part 2 (encrypted data)
				$encryption_headers = array();
				$encryption_headers[] = 'Content-Type: application/octet-stream';
				$encryption_headers[] = 'Content-Disposition: inline; filename="msg.asc"';
				$encryption_headers[] = 'Content-Transfer-Encoding: 7Bit';

				// make mime-part of headers and encrypted data
				$parts['encrypted'] = $this->flaten_email($encryption_headers, $encryption);

				// merge parts to a multipart/encrypted
				$parts = $this->mime_encode('multipart/encrypted; protocol="application/pgp-encrypted"', $parts, TRUE);

				// set the multipart/encrypted as current part
				$content_type = "multipart/encrypted";
				$part_headers = $parts['headers'];
				$email_data = $parts['body'];
			}

			// return the collected data about the email
			return array(
				'from' => $from,
				'to' => $to,
				'subject' => $subject,
				'content_type' => $content_type,
				'headers' => $base_headers + $part_headers,
				'body' => $this->flaten_body($email_data),
			);
		}

		// combine headers and body to a multipart-mime_type-part
		public function flaten_email($headers, $body)
		{
			// merge headers and body with an empty line between, all rowbreaks as CRLF (required by RFC 2822)
			return $this->flaten_headers($headers) . "\r\n\r\n" . $this->flaten_body($body);
		}

		// Convert header-array to header-string
		public function flaten_headers($headers)
		{
			// did we get an array?
			if(is_array($headers))
			{
				// merge all lines with CRLF-row-breaks
				return implode("\r\n", $headers);
			}
			else
			{
				return (string) $headers;
// 				print_r(debug_backtrace());
// 				var_dump($headers);
// 				die();
			}
		}

		// convert body to CRLF-line-breaks (required by RFC 2822)
		public function flaten_body($body)
		{
			// Remove old CR, and add new CR before each LF
			return str_replace("\n","\r\n", str_replace("\r", "", $body));
		}

		// Combine mime-parts to a multipart-mime type
		public function mime_encode($mime_type, $parts, $headers = NULL)
		{
			// create an empty list of headers
			$new_headers = array();

			// create an empty body
			// NOTICE: always start with an empty line above first boundary, or signatures fails
			$body = "\n";

			// generate a boundary
			$boundary = $this->generate_hash();
			$this->last_boundary = $boundary;

			// add headers with mime-version, type and boundary
			$new_headers['MIME-Version'] = "MIME-Version: 1.0";
			$new_headers['Content-Type'] = "Content-Type: {$mime_type}; boundary=\"{$boundary}\"";

			// for each part
			foreach($parts as $current_part)
			{
				// add a boundary and the content of the part
				$body .= "--{$boundary}\n{$current_part}\n";
			}

			// end the multipart-body whit the boundary-terminator
			$body .= "--{$boundary}--\n";

			// if asked to send headers separatly
			if($headers === TRUE)
			{
				// return an array with all data
				return array('boundary' => $boundary, 'headers' => $new_headers, 'body' => $body);
			}

			// if asked to ignore headers
			else if($headers === FALSE)
			{
				// return body only
				return $body;
			}

			// if asked to include more headers (array)
			else if(is_array($headers))
			{
				// merge all headers and the body, and return it
				return $this->flaten_email($headers + $new_headers, $body);
			}

			// if asked to include more headers (string)
			else if($headers)
			{
				// merge all headers and the body, and return it
				return trim(trim($headers) . "\r\n". $this->flaten_email($new_headers, $body));
			}

			// if no extra headers
			else
			{
				// merge headers and the body, and return it
				return $this->flaten_email($new_headers, $body);
			}
		}

		// Generate (abit) uniq hash
		public function generate_hash($length = 32)
		{
			// Can't make hashes smaller then 1 byte
			if($length < 1)
			{
				return "";
			}

			// Generate a md5-uniqid-hash (at 32 char)
			$hash = md5(uniqid(microtime()));

			// calculate the length of the hash
			$current_length = strlen($hash);

			// if correct length, return it
			if($current_length == $length)
			{
				return $hash;
			}

			// if longer then wanted, cut it and return it
			if($current_length > $length)
			{
				return substr($hash, 0, $length);
			}

			// if shorter then wanted, concat it to another hash of the missing size
			return $hash . $this->generate_hash($length - $current_length);
		}

		public function flatten_attachments($attachment, $flatten = TRUE)
		{
			// initzilize data
			$part_headers = array();
			$data = "";
			$file_mime_type = "text/plain";
			$file_name = "file.txt";

			// Create a list of new headers
			$new_headers = array();

			// If we got a list of meta-data about the file
			if(is_array($attachment))
			{
				// if array include the data
				if(isset($attachment['data']))
				{
					// use it as dataa
					$data = $attachment['data'];
				}
				// else if include a uri
				else if(isset($attachment['uri']))
				{
					// fetch the data
					$data = file_get_contents($attachment['uri']);
				}

				// if array include the mime type
				if(isset($attachment['file_mime_type']))
				{
					// use it
					$file_mime_type = $attachment['file_mime_type'];
				}
				// if array incude a uri
				else if(isset($attachment['uri']))
				{
					// create a file-info for mime-info
					$file_info = new finfo(FILEINFO_MIME);

					// read mime-type of file
					$file_mime_type = $file_info->file($attachment['uri']);
				}

				// if array include a filename
				if(isset($attachment['file_name']))
				{
					// use it
					$file_name = $attachment['file_name'];
				}
				// if array include a uri
				else if(isset($attachment['uri']))
				{
					// copy basename of the uri as filename
					$file_name = basename($attachment['uri']);
				}

				// if array include extra headers
				if(isset($attachment['headers']) AND is_array($attachment['headers']))
				{
					// use them
					$part_headers = $attachment['headers'];
				}

				if(isset($attachment['cid']))
				{
					$new_headers['Content-ID'] = "Content-ID: <{$attachment['cid']}>";
				}
			}
			// if we got the uri to a file
			else if(file_exists($attachment))
			{
				// create a file-info for mime-info
				$file_info = new finfo(FILEINFO_MIME);

				// read mime-type of file
				$file_mime_type = $file_info->file($attachment);

				// copoy basename of the uri as filename
				$file_name = basename($attachment);

				// fetch the data
				$data = file_get_contents($attachment);
			}
			// Other?
			else
			{
				// use it as data
				$data = $attachment;
			}

			// set file headers according to meta-data we collected
			$new_headers['Content-Type'] = "Content-Type: {$file_mime_type}; name=\"{$file_name}\"";
			$new_headers['Content-Disposition'] = "Content-Disposition: attachment; filename=\"{$file_name}\"";

			// bese64 encode data unless its and attatched messages?
			if($file_mime_type != "message/rfc822")
			{
				$new_headers['Content-Transfer-Encoding'] = "Content-Transfer-Encoding: base64";
				$data = chunk_split(base64_encode($data));
			}

			// merge headers with new headers
			$part_headers += $new_headers;

			// In case we wanted a flattened attatcments
			if($flatten)
			{
				// flaten and return it
				return $this->flaten_email($part_headers, $data);
			}
			else
			{
				// otherwise, return it as an array
				return array('headers' => $part_headers, 'body' => $data);
			}
		}
	}
