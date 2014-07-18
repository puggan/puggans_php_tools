<?php

	// $database = new database('use dbname', 'username', 'password');

	// Mysqli wrapper for compability with my other database-classes
	class database
	{
		private $credential = array();
		public $link = NULL;
		public $last_query = NULL;
		public $last_error = NULL;

		function __construct($database, $username = NULL, $password = NULL)
		{
			if(is_object($database))
			{
				$link = $database;
			}
			else
			{
				$credential = array();
				$credential['username'] = ($username === NULL) ? ini_get("mysqli.default_user") : $username;
				$credential['password'] = ($password === NULL) ? ini_get("mysqli.default_pw") : $password;
				$link = new mysqli('localhost', $credential['username'], $credential['password'], $database);
			}
		}

		function __destruct()
		{
			$this->link->close();
		}

		function query($query)
		{
			if(!$this->link->ping())
			{
				$this->last_error = 'No database connection';
				trigger_error('Database fel: ' . $this->last_error);
				return FALSE;
			}

			$this->last_error = NULL;
			$this->last_query = $query;

			$resource = $this->link->query($query);

			if($resource) return $resource;

			$this->last_error = $this->link->error;
			trigger_error('Database fel: ' . $this->last_error);

			return FALSE;
		}

		function write($query)
		{
			$result = $this->query($query);

			if(!$result) return FALSE;

			if($result === TRUE) return TRUE;

			$result->free();

			return FALSE;
		}

		function insert($query)
		{
			// run write query
			$result = $this->write($query);

			// return insert_id
			if($result) return $this->link->insert_id;

			return $result;
		}

		// update somthing in databasse
		function update($query)
		{
			// run write query
			$result = $this->write($query);

			// return affected rows-count
			if($result) return $this->link->affected_rows;

			// return error
			return $result;
		}

		// fetch a row or a single value
		function get($query)
		{
			// fetch first line
			$line = $this->read_line($query);

			// if a list of just one element
			if(is_array($line) AND count($line) == 1)
			{
				// return just that element
				return reset($line);
			}
			else
			{
				// return the line
				return $line;
			}
		}

		// read just one line
		function read_line($query, $column = NULL)
		{
			// FIXME: don fetch all data, if we only need one line

			// fecth all data
			$rows = $this->read($query, NULL, $column);

			// return first line
			return $rows ? $rows[0] : $rows;
		}

		// read all data, index row, or get specific column
		function read($query, $index = NULL, $column = NULL)
		{
			// run query and fetch resource
			$resource = $this->query($query);

			// return FALSE on failer
			if(!$resource) return FALSE;

			// got wrote-query answer, aborting
			if($resource === TRUE) return FALSE;

			// Nothing special
			if(!$index AND !$column)
			{
				// just fetch all
				$result = $resource->fetch_all(MYSQLI_ASSOC);
			}
			else
			{
				// Create a list of fetched data
				$result = array();

				// loop all data
				foreach($resource->fetch_all(MYSQLI_ASSOC) as $row)
				{
					// fetch row value, or complete row
					$value = $column ? $row[$column] : $row;

					// store result using index
					if($index)
					{
						$result[$row[$index]] = $value;
					}

					// store result numerical autoincremental
					else
					{
						$result[] = $value;
					}
				}
			}

			// Free resource
			$resource->free();

			// return Result
			return $result;
		}

		// Quote string, PDO-style, returning string and quote-marks
		function quote($raw_string)
		{
			return "'" . $this->link->real_escape_string($raw_string) . "'";
		}

		// convert a list of integers, to a "IN ()"-where-query-part
		function where_in_int($raw_list)
		{
			// create an empty list
			$int_list = array();

			// loop all items
			foreach($raw_list as $current)
			{
				// force to integer
				$int = (int) $current;

				// add to list
				$int_list[$int] = $int;
			}

			// sort list
			ksort($int_list);

			// return as SQL
			return "IN (" . implode(", ", $int_list) . ")";
		}
	}
?>
