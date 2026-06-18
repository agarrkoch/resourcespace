<?php
include "../include/boot.php";
include "../include/authenticate.php";

$section = getval("section", "");
$page = getval("page", "");

include "../include/header.php";


$tuesday = new DateTime('tuesday this week');

$currentStart = (clone $tuesday)->modify('-7 days');
$currentEnd   = (clone $tuesday)->modify('-1 day');

$previousStart = (clone $tuesday)->modify('-14 days');
$previousEnd   = (clone $tuesday)->modify('-8 days');




$query = "SELECT ref, name FROM collection WHERE LEFT(name, 10) BETWEEN ? AND ?;";
$curr_refs = ps_query($query, ['s', $currentStart->format('Y.m.d'), 's', $currentEnd->format('Y.m.d')]);
$prev_refs = ps_query($query, ['s', $previousStart->format('Y.m.d'), 's', $previousEnd->format('Y.m.d')]);
	
?>

<div class="BasicsBox">

<?php

echo '<h3>Current: ' . $currentStart->format('Y.m.d') . ' - ' . $currentEnd->format('Y.m.d') . '</h3>';

echo '<ul class="ref-list current">';

foreach ($curr_refs as $ref) {
    $url = 'http://resourcespace/pages/search.php?search=%21collection' . $ref['ref'];

    echo '<li>';
    echo '<a href="' . $url . '" target="_blank">';
    echo htmlspecialchars($ref['name']);
    echo '</a>';
    echo '</li>';
}

echo '</ul>';

echo '<h3>Previous: ' . $previousStart->format('Y.m.d') . ' - ' . $previousEnd->format('Y.m.d') . '</h3>';

echo '<ul class="ref-list previous">';

foreach ($prev_refs as $ref) {
    $url = 'http://resourcespace/pages/search.php?search=%21collection' . $ref['ref'];

    echo '<li>';
    echo '<a href="' . $url . '" target="_blank">';
    echo htmlspecialchars($ref['name']);
    echo '</a>';
    echo '</li>';
}

echo '</ul>';

?>

</div>

<?php
include "../include/footer.php";
?>
