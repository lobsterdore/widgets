<?php
namespace Hoborg\Widget\Graphite\Table;

include_once __DIR__ . '/../Graphite.php';

use Hoborg\Widget\Graphite\Graphite;

/**
 * conf.graphiteUrl: "http://graphs.company.net/"
 * conf.defaultTarget: {
 *   "from": "-2min",
 *   "image": {
 *     "drawNullAsZero": 1,
 *     "from": "60min",
 *     "movingAverage": 6,
 *     "lineWidth": 2,
 *     "baseline": 5,
 *     "width": 100
 *   },
 * }
 * conf.columns: [
 *   {
 *     "name": "CODE ERRORS"
 *   },
 *   {
 *     "name": "CODE WARNINGS"
 *   },
 *   {
 *     "name": "ERROR PAGES"
 *     "defaults": {
 *       "image": {
 *         "drawNullAsZero": 1,
 *         "from": "60min",
 *         "movingAverage": 6,
 *         "lineWidth": 2,
 *         "baseline": 5,
 *         "width": 100
 *       }
 *     }
 *   }
 * ]
 * conf.rows: [
 *   [
 *     // First element is alway label - it will be availabe in data.rows{}.label
 *     {
 *       "name": "www.skybet.com"
 *     },
 *     // all other elements will be available in data.rows{}.trends
 *     {
 *       "target": "lamp.sites.bet.logs.ERR",
  *       "colors": {
 *         "range": [-1, 0, 5, 10]
 *       }
 *     },
 *     {
 *       "target": "lamp.sites.bet.logs.WARN",
  *       "colors": {
 *         "range": [-1, 0, 5, 10]
 *       }
 *     },
 *     {}
 *   ],
 *   [
 *   ]
 * ]
 */
class Trend extends Graphite {

	public function bootstrap() {
		$config = $this->get('config', array());
		$data = array(
			'columns' => array(),
			'rows' => array(),
		);
		$tplName = empty($config['view']) ? 'table' : $config['view'];

		// get columns data
		$data['columns'] = $this->getColumns($config['columns']);

		// get normalization data (optional)
		$normalizations = $this->getRowsNormalization($config['rows']);

		// get rows data
		$columnDefaults = $this->getColumnDefaults($config['columns']);
		$data['rows'] = $this->getRows($config['rows'], $columnDefaults);

		foreach ($normalizations as $index => $norm) {
			if (empty($norm)) {
				continue;
			}
			$data['rows'][$index]['label']['normalization'] = $norm;
		}

		$this->data['template'] = file_get_contents(__DIR__ . "/{$tplName}.mustache");
		$this->data['data'] = $data;
	}

	/**
	 * Returns columns view data.
	 *
	 * The following fields will be removed (reserved)
	 * '_defaults'
	 *
	 * @param array $columnsConfig
	 */
	protected function getColumns(array $columnsConfig) {
		$columns = array();

		foreach ($columnsConfig as $column) {
			unset($column['_defaults']);
			$columns[] = $column;
		}

		return $columns;
	}

	protected function getRowsNormalization(array $rows) {
		$normalizations = array();
		$targets = array();
		$config = $this->get('config', array());

		foreach ($rows as $row) {
			$label = array_shift($row);
			if (empty($label['_normalization'])) {
				$normalizations[] = array(
					'target' => '1',
					'range' => array(0, 0, 2, 2)
				);
				continue;
			}

			$norm = $label['_normalization'];
			$targets[] = $norm['target'];
			$normalizations[] = array(
				'range' => $norm['range']
			);
		}
		$data = $this->getTargetsStatisticalData($config['graphiteUrl'], $targets, '-3min', 'now');

		foreach ($data as $index => $target) {
			$range = $normalizations[$index]['range'];
			$rangeSize = $range[3] - $range[0];
			$normalizations[$index]['bar'] = array(
				'min' => 100 * ($range[1]/$rangeSize),
				'max' => 100 * ($range[2]/$rangeSize),
				'avg' => 100 * ($target['avg']/$rangeSize),
				'cmax' => 100 * ($target['max']/$rangeSize),
			);
		}

		return $normalizations;
	}

	protected function getColumnDefaults(array $columnsConfig) {
		$defautls = array();

		foreach ($columnsConfig as $column) {
			if (!empty($column['_defaults'])) {
				$defautls[] = $column['_defaults'];
			} else {
				$defautls[] = array();
			}
		}

		return $defautls;
	}

	/**
	 * 
	 * @param array $rowsConfig
	 * @param array $default
	 */
	protected function getRows(array $rowsConfig, array $default) {
		if (empty($rowsConfig)) {
			return array();
		}

		$columnTargets = $newRow = $rows = array();

		// create empty arrays for each column
		for ($i = 1; $i < count($rowsConfig[0]); $i++) {
			$columnTargets[] = array();
		}

		// * get targets for each columns - we will make single graphite call for each column
		// * get row label object
		foreach ($rowsConfig as $row) {
			$newRow = $newColumns = array();

			$newRow['label'] = array_shift($row);
			$newRow['trends'] = array();

			foreach ($row as $index => $target) {
				$columnTargets[$index][] = $target + $default[$index];
			}

			$rows[] = $newRow;
		}

		foreach ($columnTargets as $colIndex => $targets) {
			$data = $this->processColumnTargets($targets);
			foreach ($data as $rowIndex => $trend) {
				$trend['avg-disp'] = ($trend['avg'] >=10) ? round($trend['avg']) : number_format($trend['avg'], 1);
				$trend['max-disp'] = ($trend['max'] >=10 || $trend['max'] == 0) ? round($trend['max']) : number_format($trend['max'], 1);
				$trend['min-disp'] = ($trend['min'] >=10 || $trend['min'] == 0) ? round($trend['min']) : number_format($trend['min'], 1);

				$trend['img'] = $this->getImageData($targets[$rowIndex], $trend);
				$trend['empty'] = !empty($targets[$rowIndex]['empty']);

				$rows[$rowIndex]['trends'][$colIndex] = $trend;
			}
		}

		return $rows;
	}

	protected function processColumnTargets(array $columnTargets) {
		$config = $this->get('config', array());
		$data = array();

		// common params
		$from = '-2min';
		$until = 'now';

		// get data from graphite
		$data = $this->getTargetsStatisticalData($config['graphiteUrl'],
				array_map(function($t) { return $t['target']; }, $columnTargets),
				$from, $until);

		return $data;
	}

	protected function getImageData(array $targetConfig, array $targetData) {
		$config = $this->get('config', array());
		$img = array(
			'src' => '',
			'width' => $targetConfig['image']['width'],
			'height' => $targetConfig['image']['height']
		);
		$until = 'now';
		$bgcolor = '282828';
		$imgFrom = empty($targetConfig['image']['from']) ? '60min' : $targetConfig['image']['from'];
		$imgWidth = empty($targetConfig['image']['width']) ? '100' : $targetConfig['image']['width'];
		$imgHeight = empty($targetConfig['image']['height']) ? '56' : $targetConfig['image']['height'];
		$coldColor = empty($targetConfig['colors']['cold']['color']) ? '070707' : $targetConfig['colors']['cold']['color'];
		$hotColor = empty($targetConfig['colors']['hot']['color']) ? 'FF0000' : $targetConfig['colors']['hot']['color'];

		$imageUrl = $config['graphiteUrl'] . "/render?from=-{$imgFrom}&until={$until}&width={$imgWidth}&height={$imgHeight}&bgcolor={$bgcolor}&hideLegend=true&hideAxes=true&margin=0&yMin=0";
		if (!empty($targetConfig['image']['drawNullAsZero'])) {
			$imageUrl .= "&drawNullAsZero=true";
		}

		if (isset($targetConfig['colors']['range'])) {
			if (2 == count($targetConfig['colors']['range'])) {
				list ($rmin, $rmax) = $targetConfig['colors']['range'];
				$rmmin = $rmin;
				$rmmax = $rmax;
			} else if (4 == count($targetConfig['colors']['range'])) {
				list ($rmmin, $rmin, $rmax, $rmmax) = $targetConfig['colors']['range'];
			}

			if ($targetData['avg'] > $rmin) {
				$color = $this->getColor($targetData['avg'], $rmax, $rmmax, $coldColor, $hotColor);
			} else {
				$color = $this->getColor($targetData['avg'], $rmmin, $rmin, $hotColor, $coldColor);
			}

			if (empty($targetConfig['image']['color'])) {
				$targetConfig['image']['color'] = $color;
				$img['color'] = $color;
			}
		}

		$origTarget = $targetConfig['target'];
		foreach ($targetConfig['image'] as $func => $val) {
			if (in_array($func, array('lineWidth', 'movingAverage'))) {
				$targetConfig['target'] = "{$func}({$targetConfig['target']}%2C{$val})";
			} else if (in_array($func, array('color'))) {
				if ('color' == $func) {
					$val = preg_replace('/#?(.*)/', '$1', $val);
				}
				$targetConfig['target'] = "{$func}({$targetConfig['target']}%2C'{$val}')";
			} else if (in_array($func, array('stacked'))) {
				if (!empty($val)) {
					$targetConfig['target'] = "{$func}({$targetConfig['target']})";
				}
			}

		}

		if (!empty($targetConfig['image']['bands'])) {
			$c = !empty($targetConfig['image']['color']) ? $targetConfig['image']['color'] : '3366FF';
			$bc = '0099ff';// $this->getColor(0, 0, 100, '000000', $c);
			$targetConfig['target'] = "color(movingAverage(holtWintersConfidenceBands(keepLastValue({$origTarget}))%2C10)%2C'{$bc}')&target={$targetConfig['target']}";
		}

		if (!empty($targetConfig['image']['baseline'])) {
			$targetConfig['target'] = "color(constantLine({$targetConfig['image']['baseline']})%2C'{$bgcolor}')&target={$targetConfig['target']}";
		}

		$imageUrl .= '&target=' . $targetConfig['target'];

		$img['src'] = $imageUrl;

		return $img;
	}

	protected function getColor($value, $min, $max, $minColor = 'FFFFFF', $maxColor = 'FF0000') {
		$value = min($max, max($min, $value));
		$value = abs($value - $min);
		$range = abs($max - $min);
		$delta = $value / $range;

		// now, lets calculate color on a 3D matrix
		list($ax, $ay, $az) = array(hexdec(substr($minColor, 0, 2)), hexdec(substr($minColor, 2, 2)),
				hexdec(substr($minColor, 4, 2)));
		list($bx, $by, $bz) = array(hexdec(substr($maxColor, 0, 2)), hexdec(substr($maxColor, 2, 2)),
				hexdec(substr($maxColor, 4, 2)));

		$cx = $ax + ($bx - $ax) * $delta;
		$cy = $ay + ($by - $ay) * $delta;
		$cz = $az + ($bz - $az) * $delta;

		return str_pad(dechex($cx), 2, '0') . str_pad(dechex($cy), 2, '0') . str_pad(dechex($cz), 2, '0');
	}
}
