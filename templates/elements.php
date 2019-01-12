<?php
$elements_tree = $this->model->_ORM->getElementsTree();

$elements = [];
if ($elements_tree) {
	foreach ($elements_tree['elements'] as $k_el => $el) {
		$links = [];

		foreach ($el['children'] as $k => $ch) {
			if (!isset($ch['element']) or !$ch['element'] or $ch['element'] == 'Element')
				continue;

			if (!in_array($ch['element'], $links))
				$links[] = $ch['element'];
		}
		if ($el['parent'] and $el['parent']['element'] and $el['parent']['element'] !== 'Element' and !in_array($el['parent']['element'], $links))
			$links[] = $el['parent']['element'];

		unset($el['children']);
		unset($el['parent']);
		$el['links'] = $links;
		$el['name'] = $k_el;

		$elements[$k_el] = $el;
	}
}
?>
<script src="https://cdnjs.cloudflare.com/ajax/libs/vis/4.21.0/vis.min.js"></script>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/vis/4.21.0/vis.min.css"/>

<style type="text/css">
	#canvas {
		width: 100%;
		height: 600px;
	}
</style>

<h2>Elements Graph</h2>

<div id="canvas"></div>

<script>
	<?php
	$nodes = [];
	$links = [];

	foreach ($elements as $el => $elData) {
		$nodes[] = ['id' => $el, 'label' => $el];

		foreach ($elData['links'] as $link) {
			$linkId = [$el, $link];
			sort($linkId);
			$linkId = implode(',', $linkId);
			$links[$linkId] = [
				'from' => $el,
				'to' => $link,
			];
		}
	}

	$links = array_values($links);
	?>
	var container = document.getElementById('canvas');

	var data = {
		nodes: new vis.DataSet(<?=json_encode($nodes)?>),
		edges: new vis.DataSet(<?=json_encode($links)?>)
	};
	var options = {};

	new vis.Network(container, data, options);
</script>