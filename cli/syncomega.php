<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 *
*
* @package    local
* @subpackage sync
* @copyright  2016-2017 Hans Jeria (hansjeria@gmail.com)
* @copyright  2016-2017 Mark Michaelsen (mmichaelsen678@gmail.com)
* @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
*/

define('CLI_SCRIPT', true);
require_once(dirname(dirname(dirname(dirname(__FILE__)))) . "/config.php");
require_once($CFG->dirroot . "/local/sync/locallib.php");
require_once ($CFG->libdir . '/clilib.php');

global $DB, $CFG;

// Now get cli options
list($options, $unrecognized) = cli_get_params(array(
		'help' => false,
		'debug' => false,
), array(
		'h' => 'help',
		'd' => 'debug'
));
if($unrecognized) {
	$unrecognized = implode("\n  ", $unrecognized);
	cli_error(get_string('cliunknowoption', 'admin', $unrecognized));
}
// Text to the sync console
if($options['help']) {
	$help =
	// Todo: localize - to be translated later when everything is finished
	"Sync Omega to get the courses and users (students and teachers).
	Options:
	-h, --help            Print out this help
	Example:
	\$sudo /usr/bin/php /local/sync/cli/tester.php";
	echo $help;
	die();
}
//heading
cli_heading('Omega Sync'); // TODO: localize
echo "\nStarting at ".date("F j, Y, G:i:s")."\n";

// Get all ID from each academic period with status is active
list($academicids, $syncinfo) = sync_getacademicperiod();

// Check we have
if($academicids){
	// Delete previous courses
	if(!$DB->execute("TRUNCATE TABLE {sync_course}")) {
		mtrace("Truncate Table sync_course: Failed");
	} else {
		mtrace("Truncate Table sync_course: Success");
	}
	// Delete previous enrol
	if(!$DB->execute("TRUNCATE TABLE {sync_enrol}")){
		mtrace("Truncate Table sync_enrol: Failed");
	}else{
		mtrace("Truncate Table sync_enrol: Success");
	}
	
	foreach ($academicids as $academicid) {
		$transaction = $DB->start_delegated_transaction();
		try {
			// Courses from Omega
			list($courses, $syncinfo) = sync_getcourses_fromomega($academicid, $syncinfo, $options["debug"]);
			// Insert the  courses
			$DB->insert_records("sync_course", $courses);
			// Users from Omega
			list($users, $syncinfo) = sync_getusers_fromomega($academicid, $syncinfo, $options["debug"]);
			// Insert the enrolments
			$DB->insert_records("sync_enrol", $users);
			/*mtrace("Error try to insert the enrolments into the database");
			mtrace("Forcing exit");
			exit(0);*/
		} catch (moodle_exception $exception) {
			$DB->rollback_delegated_transaction($transaction, $exception);
			return false;
		}
		
	}
	// insert records in sync_history
	$historyrecords = array();
	$time = time();
	foreach ($syncinfo as $academic => $rowinfo){
		$insert = new stdClass();
		$insert->dataid = $rowinfo["dataid"];
		$insert->executiondate = $time;
		$insert->countcourses = $rowinfo["course"];
		$insert->countenrols = $rowinfo["enrol"];
	
		$historyrecords[] = $insert;
		mtrace("Academic Period ".$academic.", Total courses ".$rowinfo["course"].", Total enrol ".$rowinfo["enrol"]."\n");
	}
	$DB->insert_records("sync_history", $historyrecords);
	$DB->commit_delegated_transaction($transaction);
}else{
	mtrace("No se encontraron Periodos académicos activos para sincronizar.");
	if(!$DB->execute("TRUNCATE TABLE {sync_course}")) {
		mtrace("Truncate Table sync_course Failed");
	}else{
		mtrace("Truncate Table sync_course Success");
	}
	if(!$DB->execute("TRUNCATE TABLE {sync_enrol}")){
		mtrace("Truncate Table sync_enrol Failed");
	}else{
		mtrace("Truncate Table sync_enrol Success");
	}
}
// exec("/Applications/MAMP/bin/php/php7.0.0/bin/php /Applications/MAMP/htdocs/moodle/enrol/database/cli/sync.php");
// exec("/usr/bin/php /Datos/moodle/moodle/enrol/database/cli/sync.php");
if($CFG->sync_execcommand != NULL){
	exec($CFG->sync_execcommand);
}
exit(0);