<?php

	// collect output, so we can manipulate it
	ob_start();

	// Exemple for autostart on include/require_once
	// $page = new page_generator();

	/**
	* A class for wraping output in html
	*
	* @url https://github.com/puggan/puggans_php_tools/blob/master/page.php
	**/
	class page_generator
	{
		// define class variables for collecting data
		public $title = NULL;
		public $css_content = "";
		public $css_urls = array();
		public $script_content = "";
		public $script_urls = array();
		public $headers = array();
		public $content_type;
		public $print_on_exit = TRUE;
		public $http_code = 200;
		public $http_code_text = NULL;

		// create an instance of page_generator, setting title and the content type
		public function __construct($title = NULL, $content_type = "text/html;charset=utf-8")
		{
			$this->title = $title;
			$this->content_type = $content_type;
		}

		// on exit, print page, unless we asked not to
		public function __destruct()
		{
			if($this->print_on_exit)
			{
				$this->print_html();
				$this->print_on_exit = FALSE;
			}
		}

		// Send headers and print page
		public function print_html()
		{
			$this->send_http_code();
			// TODO: add option for tidy-parsing/reparing before outputing
			echo $this->to_string();
		}

		// Send http-code and content-type if avaible
		public function send_http_code()
		{
			if($this->http_code != 200)
			{
				header("HTTP/1.1 {$this->http_code}  {$this->http_code_text}");
			}
			if($this->content_type)
			{
				header("Content-type: " . $this->content_type);
			}
		}

		// fetch and merge header, output, and footer
		public function to_string()
		{
			$body = ob_get_clean();
			$header = $this->html_header();
			$foot = $this->html_footer();
			return $header . $body . $foot;
		}

		// Generate a html-header
		public function html_header()
		{
			// create a list of headers that should be at top of the headers
			$prepend_headers = array();

			// add title to the top headers
			if(isset($this->headers['title']))
			{
				$prepend_headers['title'] = $this->headers['title'];
			}
			else
			{
				$prepend_headers['title'] = "\t\t<title>" . htmlentities($this->title) . "</title>";
			}

			// Add content-type to the top headers
			if(isset($this->headers['content_type']))
			{
				$prepend_headers['content_type'] = $this->headers['content_type'];
			}
			else
			{
				$prepend_headers['content_type'] = "\t\t<meta http-equiv=\"Content-Type\" content=\"{$this->content_type}\" />";
			}

			// merge all headers
			$this->headers = $prepend_headers + $this->headers;

			// if we have css-urls, add thous in to headers
			if($this->css_urls)
			{
				foreach($this->css_urls as $current_css_url)
				{
					$this->headers[] = "\t\t<link rel=\"stylesheet\" type=\"text/css\" href=\"" . htmlentities($current_css_url) . "\" />";
				}
				unset($this->css_urls);
			}

			// if we have script-urls, add thous in to headers
			if($this->script_urls)
			{
				foreach($this->script_urls as $current_js_url)
				{
					$this->headers[] = "\t\t<script type=\"text/javascript\" src=\"" . htmlentities($current_js_url) . "\"></script>";
				}
				unset($this->script_urls);
			}

			// if we have script-content, add that in to headers
			if($this->script_content)
			{
				$this->headers[] = "\t\t<script type=\"text/javascript\">{$this->script_content}</script>";
				unset($this->script_content);
			}

			// if we have css-content, add that in to headers
			if($this->css_content)
			{
				$this->headers[] = "\t\t<style type=\"text/css\">{$this->css_content}</style>";
				unset($this->css_content);
			}

			// Merge all headers to a string
			$html_headers = implode("\n", $this->headers);

			// return header html
			return <<<HTML_BLOCK
<html>
	<head>
{$html_headers}
	</head>
	<body>
		<div class='page'>

HTML_BLOCK;
		}

		// generate a html-footer
		public function html_footer()
		{
			// return footer html
			return <<<HTML_BLOCK

		</div>
	</body>
</html>
HTML_BLOCK;
		}
	}
