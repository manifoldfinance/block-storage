#!/usr/bin/php -q
<?php
/**
 * produces test result metrics from fio result files (fio-[test].json)
 */
$status = 1;
foreach(array('iops', 'throughput', 'latency', 'wsat', 'hir', 'xsr', 'ecw', 'dirth') as $test) {
  if (isset($argv[1]) && file_exists($argv[1]) && 
      file_exists($json = sprintf('%s/fio-%s.json', dirname($argv[1]), $test)) && 
      ($results = json_decode(file_get_contents($json), TRUE))) {
    // TODO
  }
}
exit($status);
?>
