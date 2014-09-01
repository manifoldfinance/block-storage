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
 * saves results based on the arguments defined in ../run.sh
 */
require_once(dirname(__FILE__) . '/BlockStorageTest.php');
require_once(dirname(__FILE__) . '/save/BenchmarkDb.php');
$status = 1;
$args = parse_args(array('nosave_fio', 'nostore_json', 'nostore_pdf', 'nostore_zip', 'v' => 'verbose'));

// get result directories => each directory stores 1 iteration of results
$dirs = array();
$dir = count($argv) > 1 && is_dir($argv[count($argv) - 1]) ? $argv[count($argv) - 1] : trim(shell_exec('pwd'));
if (is_dir(sprintf('%s/1', $dir))) {
  $i = 1;
  while(is_dir($sdir = sprintf('%s/%d', $dir, $i++))) $dirs[] = $sdir;
}
else $dirs[] = $dir;

if ($db =& BenchmarkDb::getDb()) {
  // get results from each directory
  foreach($dirs as $i => $dir) {
    $iteration = $i + 1;
    print_msg(sprintf('Saving results in directory %s', $dir), isset($args['verbose']), __FILE__, __LINE__);
    
    // save report.pdf and report.zip
    foreach(array('nostore_pdf' => 'report.pdf', 'nostore_zip' => 'report.zip') as $arg => $file) {
      $file = sprintf('%s/%s', $dir, $file);
      if (!isset($args[$arg]) && file_exists($file)) {
        $pieces = explode('.', $file);
        $col = sprintf('report_%s', $pieces[count($pieces) - 1]);
        $saved = $db->saveArtifact($file, $col);
        if ($saved) print_msg(sprintf('Saved %s successfully', basename($file)), isset($args['verbose']), __FILE__, __LINE__);
        else if ($saved === NULL) print_msg(sprintf('Unable to save %s', basename($file)), isset($args['verbose']), __FILE__, __LINE__, TRUE);
      }
      else if (file_exists($file)) print_msg(sprintf('Artifact %s will not be saved because --%s was set', basename($file), $arg), isset($args['verbose']), __FILE__, __LINE__);
    }
    
    foreach(BlockStorageTest::getSupportedTests() as $test) {
      if (file_exists($file = sprintf('%s/%s.json', $dir, $test)) && ($results = json_decode(file_get_contents($file), TRUE))) {
        // save job specific results
        $results['iteration'] = $iteration;
        $results = array_merge(BlockStorageTest::getMetaCols($dir), $results);
        if ($db->addRow($test, $results)) print_msg(sprintf('Successfully saved %s test results', $test), isset($args['verbose']), __FILE__, __LINE__);
        else print_msg(sprintf('Failed to save %s test results', $test), isset($args['verbose']), __FILE__, __LINE__, TRUE);
        
        // save fio results
        if (!isset($args['nosave_fio'])) {
          $files = $test == 'throughput' ? array(sprintf('%s/fio-%s-1024k.json', $dir, $test), sprintf('%s/fio-%s-128k.json', $dir, $test)) : array(sprintf('%s/fio-%s.json', $dir, $test));
          foreach($files as $file) {
            if (file_exists($file) && ($results = json_decode(file_get_contents($file), TRUE)) && isset($results['jobs'])) {
              $njobs = count($results['jobs']);
              foreach($results['jobs'] as $i => $job) {
                if ($row = BlockStorageTest::getFioJobRow($job)) {
                  $row['test'] = $test;
                  $row['iteration'] = $iteration;
                  $row = array_merge(BlockStorageTest::getMetaCols($dir), $row);
                  if ($db->addRow('fio', $row)) print_msg(sprintf('Successfully saved job %s to fio table', $row['jobname']), isset($args['verbose']), __FILE__, __LINE__);
                  else print_msg(sprintf('Failed to save job %s to fio table', $row['jobname']), isset($args['verbose']), __FILE__, __LINE__, TRUE);
                }
                else print_msg(sprintf('Unable to get fio row data for job %s', $row['jobname']), isset($args['verbose']), __FILE__, __LINE__, TRUE);
              }
            }
            else print_msg(sprintf('Failed to save fio results from file %s', basename($file)), isset($args['verbose']), __FILE__, __LINE__, TRUE);
          }
        }
        else print_msg(sprintf('%s fio results will not be saved because the --nosave_fio argument was set', $test), isset($args['verbose']), __FILE__, __LINE__);
      }
      else print_msg(sprintf('Skipping test %s because results are not present', $test), isset($args['verbose']), __FILE__, __LINE__);
    }
  }
  
  // finalize saving of results
  if ($db->save()) {
    print_msg(sprintf('Successfully saved test results from directory %s', $dir), isset($args['verbose']), __FILE__, __LINE__);
    $status = 0;
  }
  else {
    print_msg(sprintf('Unable to save test results from directory %s', $dir), isset($args['verbose']), __FILE__, __LINE__, TRUE);
    $status = 1;
    break;
  }
}

exit($status);
?>
