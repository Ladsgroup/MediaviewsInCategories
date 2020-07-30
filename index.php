<?php
require_once( 'header.php' );
error_reporting(E_ERROR|E_CORE_ERROR|E_COMPILE_ERROR); // E_ALL|
ini_set('display_errors', 'On');

$category = isset( $_REQUEST['category'] ) ? $_REQUEST['category'] : '';
$timespan = htmlspecialchars( isset( $_REQUEST['timespan'] ) ? $_REQUEST['timespan'] : '');

if (isset($_REQUEST['limit']) && $_REQUEST['limit']) {
	$limit = (int)$_REQUEST['limit'];
} else {
	$limit = 50;
}
?>
<script>
$(function() {
	$('table.sortable').tablesort();
});
</script>
<div style="padding: 3em;">
<form action="<?php echo basename( __FILE__ ); ?>">
  <label for="category">Category (without "Category:")</label><br>
  <div class="ui corner labeled input">
  <input style="margin-bottom: 0.5em" type="text" name="category" id="category" required placeholder="Alan Turing" <?php
  if ( $category !== '' ) {
	echo 'value="' . htmlspecialchars( $category ) . '"';
  }
?>><div class="ui corner label">
<i class="asterisk icon"></i>
</div></div><br>
<label for="timespan">Timespan:</label><br>
  <div class="ui corner labeled input">
<select class="ui selection dropdown" name="timespan" id="timespan">
    <option selected="" value="now-90">Last three months</option>
    <option value="now-30">Last month</option>
    <option value="now-7">Last week</option>
  </select>
  </div><br>
<label for="limit">Limit</label><br>
<div class="ui labeled input">
  <input style="margin-bottom: 0.5em" id="limit" name="limit" type="number" min="1" max="500" required value="<?php echo htmlspecialchars( $limit ); ?>">
</div>
<br>
<?php
function checkbox( $name, $description, $checked ) {
	$checkedAttribute = $checked ? 'checked' : '';
	echo <<< EOF
<div class="ui checkbox">
  <input type="checkbox" name="$name" id="$name" $checkedAttribute>
  <label for="$name">$description</label>
</div>
EOF;
}

checkbox( 'recursive', 'Recursive (currently not working)', isset( $_REQUEST['recursive'] ) );
?>
<br>
  <button type="submit" class="ui primary button">Get data</button>
</form>
<?php

if ( $category ) {
	$category = str_replace( ' ', '_', $category );
	$limit = addslashes( (string)min( [ $limit, 500 ] ) );
	$dbmycnf = parse_ini_file("../replica.my.cnf");
	$dbuser = $dbmycnf['user'];
	$dbpass = $dbmycnf['password'];
	unset($dbmycnf);
	$dbhost = "commonswiki.web.db.svc.eqiad.wmflabs";
	$dbname = "commonswiki_p";
	$db = new PDO('mysql:host='.$dbhost.';dbname='.$dbname.';charset=utf8', $dbuser, $dbpass);
	$conditions = [];
	$conditions[] = 'cl_to="' . $category . '"';
	$where = '(' . implode( ' OR ', $conditions ) . ')';
	$sql = "SELECT page_title " .
		"FROM categorylinks " .
		"JOIN page ON cl_from = page_id " .
		"WHERE {$where} AND page_namespace = 6 " .
		"LIMIT {$limit};";
	$result = $db->query($sql)->fetchAll();
	$entities = [];
	foreach ($result as $row) {
		$entities[] = $row['page_title'];
	}
	$start = explode( '-', $timespan)[1];
	$end = explode( '-', $timespan)[0];
	if ( $end == 'now' ) {
		$endDate = date("Ymd") . '00';
		$end = 0;
	} else {
		$endDate = date('Ymd', strtotime("-{$end} days", time())) . '00';
	}
	$startDate = date('Ymd', strtotime("-{$start} days", time())) . '00';
	$urls = [];
	foreach ( array_chunk( $entities, 50 )  as $chunk ) {
		$params = [
			'action' => 'query',
			'titles' => 'File:' . implode('|File:', $chunk),
			'prop' => 'imageinfo',
			'format' => 'json',
			'iiprop' => 'url'
		];
		$apiresult = json_decode(
			file_get_contents('https://commons.wikimedia.org/w/api.php?' . http_build_query($params)
			), true);
		foreach ($apiresult['query']['pages'] as $id => $values ) {
			$urls[] = $values['imageinfo'][0]['url'];
		}
	}

	$aqs = 'https://wikimedia.org/api/rest_v1/metrics/mediarequests/per-file/all-referers/all-agents/';
	$total = 0;
	$finalResult = [];
	foreach ( $urls as $url ) {
		$fileTotal = 0;
		$url = str_replace('https://upload.wikimedia.org', '', $url);
		$url = urlencode(implode('/', array_slice(explode( '/', $url), 0,5)) . '/') . implode('/', array_slice(explode( '/', $url), 5));
		$resultUrl = $aqs . $url . "/daily/{$startDate}/{$endDate}";
		$mediaViewData = json_decode(file_get_contents($resultUrl), true)['items'];
		foreach ( $mediaViewData as $case ) {
			$fileTotal += $case['requests'];
		}
		$finalResult[$case['file_path']] = [$fileTotal, $resultUrl];
		$total += $fileTotal;
	}
	echo '</br><b>Total: </b>' . number_format($total) . '</br>';
	echo '</br><b>Timespan: </b> from ' . date('Y-m-d', strtotime("-{$start} days", time())) . ' to ' . date('Y-m-d', strtotime("-{$end} days", time())) . '</br>'; 
	echo '<table class="ui sortable celled table"><thead><tr><th>File name</th><th>File path</th><th>Views</th></tr></thead><tbody>';
	echo "\n";
	foreach ($finalResult as $filePath => $values ) {
		list( $views, $viewsUrl ) = $values;
		$fileName = implode('/', array_slice(explode( '/', $filePath), 5));
		$thumb = str_replace('wikipedia/commons/', 'wikipedia/commons/thumb/', $filePath);
		$viewsFormatted = number_format( $views );
		echo "<tr><td><a href=https://commons.wikimedia.org/wiki/File:{$fileName} target='_blank'>{$fileName}</a></td><td><a href=https://upload.wikimedia.org{$filePath} target='_blank'><img src=\"https://upload.wikimedia.org{$thumb}/176px-{$fileName}\"></a></td><td data-sort-value=\"$views\"><a href=\"{$viewsUrl}\">{$viewsFormatted}</a></tr>\n";
	}
	echo "</table>\n";
} else {
    echo '<div class="ui negative message">
    <div class="header">
     No query
    </div>
    <p>You need to set category</p></div>';
}

?>
</div>
</body>
</html>
