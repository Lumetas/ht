@baseUrl = http://localhost:8000

### #main

<?php
	$i = 0;
	while($i < 10) {
		$api->set('a', $i);
		$api->send('loop');
		$i++;
	}
?>

### #loop
POST {{baseUrl}}/login?a={{a}}&b={{b}}

<?php
	#pre
	$api->set('b', $api->get('a'));
	#post
	$body = $response->body;
	$numbers = explode(' ', $body);
	foreach($numbers as &$num) { $num = (int)$num; }
	$output->write(array_sum($numbers));
?>
