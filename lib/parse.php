#!/usr/bin/php -q
<?php
// Copyright 2014 CloudHarmony Inc.
// 
// Licensed under the Apache License, Version 2.0 (the "License");
// you may not use this file except in compliance with the License.
// You may obtain a copy of the License at
// 
//     http://www.apache.org/licenses/LICENSE-2.0
// 
// Unless required by applicable law or agreed to in writing, software
// distributed under the License is distributed on an "AS IS" BASIS,
// WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
// See the License for the specific language governing permissions and
// limitations under the License.


/**
 * produces test result metrics from fio result files (fio-[test].json)
 */
require_once('BlockStorageTest.php');
$status = 1;
if (isset($argv[1]) && file_exists($argv[1])) {
  $dir = is_dir($argv[1]) ? $argv[1] : dirname($argv[1]);
  foreach(array('iops', 'throughput', 'latency', 'wsat', 'hir', 'xsr', 'ecw', 'dirth') as $test) {
    $files = $test == 'throughput' ? array(sprintf('%s/fio-%s-1024k.json', $dir, $test), sprintf('%s/fio-%s-128k.json', $dir, $test)) : array(sprintf('%s/fio-%s.json', $dir, $test));
    foreach($files as $file) {
      if (file_exists($file) && ($results = json_decode(file_get_contents($file), TRUE)) && isset($results['jobs'])) {
        $njobs = count($results['jobs']);
        foreach($results['jobs'] as $i => $job) {
          $idx = $njobs ? $i+1 : '';
          BlockStorageTest::printJob($job, $dir, $test, $idx);
        }
      }
    }
  }
}
exit($status);
?>
