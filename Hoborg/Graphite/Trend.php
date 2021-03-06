<?php

class GraphiteTrendWidget extends \Hoborg\Dashboard\Widget {

	public function bootstrap() {
		$widget = $this->get();
		$config = $this->get('conf', array());

		// main check for data file.
		if (empty($config['targets'])) {
			$widget['body'] = 'Missing or empty data configuration `widget.conf.targets`';
			$widget['error'] = 'Missing or empty data configuration `widget.conf.targets`';

			$this->data = $widget;
			return;
		}

		$targetStats = array();
		foreach ($config['targets'] as $targetConf) {
		    $targetStats[] = $this->processTarget($targetConf);
		}

		ob_start();
		include __DIR__ . '/view.phtml';
		$widget['body'] = ob_get_clean();
		$widget['template'] = '{{{body}}}';

		$this->data = $widget;
	}

	protected function processTarget(array $targetConf) {
	    $widget = $this->data;
	    $target = $targetConf['target'];
	    $factor = empty($targetConf['factor']) ? 1 : $targetConf['factor'];
	    $from = empty($targetConf['from']) ? '-10min' : $targetConf['from'];
	    $until = 'now';
	    $graphiteUrl = 'http://graphs.skybet.net';

	    $t = time();
	    $imageUrl = $graphiteUrl . "/render?from=-30min&until={$until}&width=100&height=56&bgcolor=282828&hideLegend=true&hideAxes=true&margin=0&t={$t}";
		if (!empty($targetConf['image']['bands'])) {
			$imageUrl .= "&target=color(movingAverage(holtWintersConfidenceBands({$target})%2C10)%2C'B5AB81')&target=lineWidth(color({$target}%2C'3366FF')%2C2)";
		} else {
			$imageUrl .= "&target={$target}";
		}
	    $dataUrl = $graphiteUrl . "/render?from={$from}&until={$until}&target={$target}&format=json";

	    $jsonData = file_get_contents($dataUrl);
	    if (empty($jsonData)) {
	        $widget['body'] = 'no data';
	        $widget['error'] = 'no data';

	        $this->data = $widget;
	        return;
	    }

	    $data = json_decode($jsonData, true);
	    $datapoints = $data[0]['datapoints'];

	    $stats = array();
	    $first = array_shift($datapoints);
	    $min = $max = $sum = $prev = $first[0];
	    $stats[] = $min;
	    $delta = 0;
	    foreach ($datapoints as $datapoint) {
	        $s = $datapoint[0];
	        if (null === $s) {
	            continue;
	        }
	        $delta += ($s - $prev);
	        $min = min($min, $s);
	        $max = max($max, $s);
	        $sum += $s;

	        $stats[] = $s;
	        $prev = $s;
	    }
	    $avg = round($sum / (count($stats) * $factor));
	    $delta = round($delta / $factor);

	    $class = ($delta > 0) ? 'positive' : 'negative';
	    if ($delta == 0) {
	        $class = 'neutral';
	    }
	    $class = "text-S icon {$class}";

	    $coldColor = empty($targetConf['colors']['cold']['color']) ? 'FFFFFF' : $targetConf['colors']['cold']['color'];
	    $coldValue = empty($targetConf['colors']['cold']['value']) ? 0 : $targetConf['colors']['cold']['value'];
	    $hotColor = empty($targetConf['colors']['hot']['color']) ? 'FF0000' : $targetConf['colors']['hot']['color'];
	    $hotValue = empty($targetConf['colors']['hot']['value']) ? 100 : $targetConf['colors']['hot']['value'];

	    $targetConf += array(
	            'name' => $targetConf['label'],
	            'avg' => $avg,
	            'delta' => empty($delta) ? '' : $delta,
	            'min' => round($min / $factor),
	            'max' => round($max / $factor),
	            'class' => $class,
	            'img' => $imageUrl,
	            'img-link' => isset($targetConf['image']['link']) ? $targetConf['image']['link'] : '',
	    		'color' => $this->getColor($avg, $coldValue, $hotValue, $coldColor, $hotColor)
	    );

	    return $targetConf;
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
