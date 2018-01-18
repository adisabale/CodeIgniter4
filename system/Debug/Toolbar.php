<?php namespace CodeIgniter\Debug;

/**
 * CodeIgniter
 *
 * An open source application development framework for PHP
 *
 * This content is released under the MIT License (MIT)
 *
 * Copyright (c) 2014-2018 British Columbia Institute of Technology
 *
 * Permission is hereby granted, free of charge, to any person obtaining a copy
 * of this software and associated documentation files (the "Software"), to deal
 * in the Software without restriction, including without limitation the rights
 * to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
 * copies of the Software, and to permit persons to whom the Software is
 * furnished to do so, subject to the following conditions:
 *
 * The above copyright notice and this permission notice shall be included in
 * all copies or substantial portions of the Software.
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 * OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN
 * THE SOFTWARE.
 *
 * @package      CodeIgniter
 * @author       CodeIgniter Dev Team
 * @copyright    2014-2018 British Columbia Institute of Technology (https://bcit.ca/)
 * @license      https://opensource.org/licenses/MIT	MIT License
 * @link         https://codeigniter.com
 * @since        Version 3.0.0
 * @filesource
 */
use CodeIgniter\Config\BaseConfig;
use Config\Services;
use CodeIgniter\Format\XMLFormatter;

/**
 * Debug Toolbar
 *
 * Displays a toolbar with bits of stats to aid a developer in debugging.
 *
 * Inspiration: http://prophiler.fabfuel.de
 *
 * @package CodeIgniter\Debug
 */
class Toolbar
{

	/**
	 * Collectors to be used and displayed.
	 *
	 * @var array
	 */
	protected $collectors = [];

	//--------------------------------------------------------------------

	/**
	 * Constructor
	 *
	 * @param BaseConfig $config
	 */
	public function __construct(BaseConfig $config)
	{
		foreach ($config->toolbarCollectors as $collector)
		{
			if (! class_exists($collector))
			{
				// @todo Log this!
				continue;
			}

			$this->collectors[] = new $collector();
		}
	}

	//--------------------------------------------------------------------

	/**
	 * Returns all the data required by Debug Bar
	 *
	 * @param float                               $startTime   App start time
	 * @param float                               $totalTime
	 * @param float                               $startMemory
	 * @param \CodeIgniter\HTTP\RequestInterface  $request
	 * @param \CodeIgniter\HTTP\ResponseInterface $response
	 *
	 * @return string JSON encoded data
	 */
	public function run($startTime, $totalTime, $startMemory, $request, $response): string
	{
		// Data items used within the view.
		$data['startTime']       = $startTime;
		$data['totalTime']       = $totalTime*1000;
		$data['totalMemory']     = number_format((memory_get_peak_usage()-$startMemory)/1048576, 3);
		$data['segmentDuration'] = $this->roundTo($data['totalTime']/7, 5);
		$data['segmentCount']    = (int)ceil($data['totalTime']/$data['segmentDuration']);
		$data['CI_VERSION']      = \CodeIgniter\CodeIgniter::CI_VERSION;
		$data['collectors']      = [];

		foreach($this->collectors as $collector)
		{
			$data['collectors'][] = [
				'title'           => $collector->getTitle(),
				'titleSafe'       => $collector->getTitle(true),
				'titleDetails'    => $collector->getTitleDetails(),
				'display'         => $collector->display(),
				'badgeValue'      => $collector->getBadgeValue(),
				'isEmpty'         => $collector->isEmpty(),
				'hasTabContent'   => $collector->hasTabContent(),
				'hasLabel'        => $collector->hasLabel(),
				'icon'            => $collector->icon(),
				'hasTimelineData' => $collector->hasTimelineData(),
				'timelineData'    => $collector->timelineData(),
			];
		}

		foreach ($this->collectVarData() as $heading => $items)
		{
			$vardata = [];

			if (is_array($items))
			{
				foreach ($items as $key => $value)
				{
					$vardata[esc($key)] = is_string($value) ? esc($value) : print_r($value, true);
				}
			}

			$data['vars']['varData'][esc($heading)] = $vardata;
		}

		if (isset($_SESSION) && ! empty($_SESSION))
		{
			foreach ($_SESSION as $key => $value)
			{
				$data['vars']['session'][esc($key)] = is_string($value) ? esc($value) : print_r($value, true);
			}
		}

		foreach ($request->getGet() as $name => $value)
		{
			$data['vars']['get'][esc($name)] = esc($value);
		}

		foreach ($request->getPost() as $name => $value)
		{
			$data['vars']['post'][esc($name)] = is_array($value) ? esc(print_r($value, true)) : esc($value);
		}

		foreach ($request->getHeaders() as $header => $value)
		{
			if (empty($value))
			{
				continue;
			}

			if (! is_array($value))
			{
				$value = [$value];
			}

			foreach ($value as $h)
			{
				$data['vars']['headers'][esc($h->getName())] = esc($h->getValueLine());
			}
		}

		foreach ($request->getCookie() as $name => $value)
		{
			$data['vars']['cookies'][esc($name)] = esc($value);
		}

		$data['vars']['request'] = ($request->isSecure() ? 'HTTPS' : 'HTTP').'/'.$request->getProtocolVersion();

		$data['vars']['response'] = [
			'statusCode' => $response->getStatusCode(),
			'reason'     => esc($response->getReason()),
		];

		$data['config'] = \CodeIgniter\Debug\Toolbar\Collectors\Config::display();

		return json_encode($data);
	}

	//--------------------------------------------------------------------

	/**
	 * Format output
	 *
	 * @param  string $data   JSON encoded Toolbar data
	 * @param  string $format html, json, xml
	 *
	 * @return string
	 */
	protected static function format(string $data, string $format = 'html')
	{
		if ($format === 'json')
		{
			return $data;
		}

		$data   = json_decode($data, true);
		$output = '';

		if ($format === 'html')
		{
			extract($data);
			$parser = \Config\Services::parser(BASEPATH . 'Debug/Toolbar/Views/', null,false);
			ob_start();
			include(__DIR__.'/Toolbar/Views/toolbar.tpl.php');
			$output = ob_get_contents();
			ob_end_clean();
		}
		elseif ($format === 'xml')
		{
			$formatter = new XMLFormatter;
			$output    = $formatter->format($data);
		}

		return $output;
	}

	//--------------------------------------------------------------------

	/**
	 * Called within the view to display the timeline itself.
	 *
	 * @param array $collectors
	 * @param float $startTime
	 * @param int   $segmentCount
	 * @param int   $segmentDuration
	 *
	 * @return string
	 */
	protected static function renderTimeline(array $collectors, $startTime, int $segmentCount, int $segmentDuration): string
	{
		$displayTime = $segmentCount*$segmentDuration;

		$rows = self::collectTimelineData($collectors);

		$output = '';

		foreach ($rows as $row)
		{
			$output .= "<tr>";
			$output .= "<td>{$row['name']}</td>";
			$output .= "<td>{$row['component']}</td>";
			$output .= "<td style='text-align: right'>".number_format($row['duration']*1000, 2)." ms</td>";
			$output .= "<td colspan='{$segmentCount}' style='overflow: hidden'>";

			$offset = ((($row['start']-$startTime)*1000)/
			           $displayTime)*100;
			$length = (($row['duration']*1000)/$displayTime)*100;

			$output .= "<span class='timer' style='left: {$offset}%; width: {$length}%;' title='".number_format($length,
					2)."%'></span>";

			$output .= "</td>";

			$output .= "</tr>";
		}

		return $output;
	}

	//--------------------------------------------------------------------

	/**
	 * Returns a sorted array of timeline data arrays from the collectors.
	 *
	 * @return array
	 */
	protected static function collectTimelineData($collectors): array
	{
		$data = [];

		// Collect it
		foreach ($collectors as $collector)
		{
			if (! $collector['hasTimelineData'])
			{
				continue;
			}

			$data = array_merge($data, $collector['timelineData']);
		}

		// Sort it

		return $data;
	}

	//--------------------------------------------------------------------

	/**
	 * Returns an array of data from all of the modules
	 * that should be displayed in the 'Vars' tab.
	 *
	 * @return array
	 */
	protected function collectVarData()// : array
	{
		$data = [];

		foreach ($this->collectors as $collector)
		{
			if (! $collector->hasVarData())
			{
				continue;
			}

			$data = array_merge($data, $collector->getVarData());
		}

		return $data;
	}

	//--------------------------------------------------------------------

	/**
	 * Rounds a number to the nearest incremental value.
	 *
	 * @param float $number
	 * @param int   $increments
	 *
	 * @return float
	 */
	protected function roundTo($number, $increments = 5)
	{
		$increments = 1/$increments;

		return (ceil($number*$increments)/$increments);
	}

	//--------------------------------------------------------------------

	/**
	 *
	 */
	public static function eventHandler()
	{
		$request = Services::request();

		// If the request contains '?debugbar then we're
		// simply returning the loading script
		if ($request->getGet('debugbar') !== null)
		{
			// Let the browser know that we are sending javascript
			header('Content-Type: application/javascript');

			ob_start();
			include(BASEPATH.'Debug/Toolbar/toolbarloader.js.php');
			$output = ob_get_contents();
			@ob_end_clean();

			exit($output);
		}

		// Otherwise, if it includes ?debugbar_time, then
		// we should return the entire debugbar.
		if ($request->getGet('debugbar_time'))
		{
			helper('security');

			$format = $request->negotiate('media', [
				'text/html',
				'application/json',
				'application/xml'
			]);
			$format = explode('/', $format)[1];

			$file     = sanitize_filename('debugbar_'.$request->getGet('debugbar_time'));
			$filename = WRITEPATH.'debugbar/'.$file;

			// Show the toolbar
			if (file_exists($filename))
			{
				$contents = self::format(file_get_contents($filename), $format);
				//unlink($filename); // TODO - Keep history? 10 files
				exit($contents);
			}

			// File was not written or do not exists
			$error = 'CI DebugBar: File "WRITEPATH/'.$file.'" not found.';
			switch ($format)
			{
				case 'json':
					header('Content-Type: application/json');
					exit($error);
				case 'xml':
					header('Content-Type: application/xml');
					exit('<?xml version="1.0" encoding="UTF-8"?><error>'.$error.'</error>');
				default:
					header('Content-Type: application/javascript');
					exit('<script id="toolbar_js">console.log(\''.$error.'\')</script>');
			}
		}
	}
}
