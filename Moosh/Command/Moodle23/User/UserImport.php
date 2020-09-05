<?php
/**
 * moosh - Moodle Shell
 *
 * @copyright  2016 onwards Tomasz Muras
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace Moosh\Command\Moodle23\User;
use context_coursecat;
use Moosh\MooshCommand;
use stdClass;

class UserImport extends MooshCommand
{
    public function __construct()
    {
        parent::__construct('import', 'user');

        $this->addArgument('file');

	    $this->addOption('d|delimiter:=string', 'csv delimiter', ',');
	    $this->addOption('f|field:=string', 'user matching field', 'idnumber');
	    $this->addOption('U|update-user', 'should user be updated if exists');
	    $this->addOption('offset:=number', 'the offset to be ran from', 0);
	    $this->addOption('limit:=number', 'the maximum number of rows to process (-1 for no limit)', -1);
    }

    public function execute()
    {
	    global $CFG;

	    require_once $CFG->dirroot . '/user/lib.php';
	    require_once $CFG->dirroot . '/cohort/lib.php';

	    unset($CFG->passwordpolicy);

	    $options = $this->expandedOptions;

    	$offset = 0;
    	$limit = -1;
	    if (isset($options['offset']) && (!is_numeric($options['offset']) || ($offset = (int) $options['offset']) < 0)) {
		    cli_error('Offset should be a positive number');
	    }
	    if (isset($options['limit']) && (!is_numeric($options['limit']) || ($limit = (int) $options['limit']) < -1)) {
		    cli_error('Limit should be a positive number or a -1');
	    }
    	$delimiter = $options['delimiter'];
	    $updateUser = isset($options['update-user']) ? $options['update-user'] : false;

    	$h = fopen($this->arguments[0], 'rb');
    	$header = fgetcsv($h, 0, $delimiter);
    	$this->assertHeader($header);

    	$lineId = 0;
    	$processedCount = 0;
	    $usersCreatedCount = 0;
	    $usersUpdatedCount = 0;
	    $cohortsCreatedCount = 0;
    	while (($row = fgetcsv($h, 0, $delimiter)) !== false) {
    		$lineId++;
    		if ($offset > 0) {
			    $offset--;
    			continue;
		    }
    		if ($limit === 0) {
    			break;
		    }
    		if ($limit !== -1) {
    			$limit--;
		    }
    		$data = array_combine($header, $row);
    		$user = $this->getOrUpsertUser($data, $updateUser, $wasCreated);
    		if ($wasCreated) {
    			$usersCreatedCount++;
		    } elseif ($updateUser) {
    			$usersUpdatedCount++;
		    }
    		if (!empty($data['cohort-name'])) {
    		    $cohortId = $this->getOrCreateCohort($data, $wasCreated);
    		    cohort_add_member($cohortId, $user->id);
    		    if ($wasCreated) {
    		    	$cohortsCreatedCount++;
		        }
		    }
    		$this->debug("Processed line {$lineId}");
		    $processedCount++;
	    }

	    fclose($h);
    	echo 'Import complete'.PHP_EOL;
	    $this->debug("Lines scanned:  \t{$lineId}");
	    $this->debug("Lines processed:\t{$processedCount}");
	    $this->debug("Users created:  \t{$usersCreatedCount}");
	    if ($updateUser) {
		    $this->debug("Users updated:  \t{$usersUpdatedCount}");
	    }
	    $this->debug("Cohorts created:\t{$cohortsCreatedCount}");
    }

	private function assertHeader( array $header ) {
		if (!in_array($this->expandedOptions['field'], $header, true)) {
			cli_error("Field {$this->expandedOptions['field']} not found in file, but should exist for matching to work");
		}
		$requiredFields = ['firstname', 'lastname', 'email', 'username'];
		foreach ($requiredFields as $field) {
			if (!in_array($field, $header, true)) {
				cli_error("Field {$field} not found in file, but should exist to import users");
			}
		}
	}

	private function getOrUpsertUser( array $data, $updateUser, &$wasCreated = false ) {
    	global $DB, $CFG;

		$matchingField = $this->expandedOptions['field'];
		$matchingValue = $data[$matchingField];

		$supportedKeys = array_filter($DB->get_columns('user'), static function ($column) {
			return !in_array($column, ['id', 'mnethostid', 'timecreated', 'timemodified'], true);
		});
		$userData = array_intersect_key($data, array_reverse($supportedKeys));

		$user = $DB->get_record('user', [ $matchingField => $matchingValue ]);
		if ($user === false) {
			$user = new stdClass();

			$user->confirmed = 1;
			$user->mnethostid = $CFG->mnet_localhost_id;
			$user->timecreated = time();
			$user->timemodified = $user->timecreated;
			foreach ($userData as $key => $value) {
				$user->{$key} = $value;
			}

			if (isset($user->auth) && $user->auth !== 'manual') {
				$userId = $DB->insert_record('user', $user);
			} else {
				$userId = user_create_user($user);
			}
			$wasCreated = true;

			$this->debug("User {$userId} with email {$user->email} created");

			$user = $user = $DB->get_record('user', [ 'id' => $userId ]);
		} elseif ($updateUser) {
			$user->timemodified = time();
			foreach ($userData as $key => $value) {
				$user->{$key} = $value;
			}

			if (isset($user->auth) && $user->auth !== 'manual') {
				$DB->update_record('user', $user);
			} else {
				user_update_user($user);
			}

			$this->debug("User {$user->id} with email {$user->email} updated");
		}

		return $user;
	}

	private function getOrCreateCohort( array $data , &$wasCreated = false ) {
    	global $DB;

    	$cohort = $DB->get_record('cohort', ['name' => $data['cohort-name']]);
    	if ($cohort === false) {
    		$cohort = new stdClass();
    		$cohort->name = $data['cohort-name'];
    		$cohort->idnumber = isset($data['cohort-idnumber']) ? $data['cohort-idnumber'] : $cohort->name;
    		$cohort->description = isset($data['cohort-description']) ? $data['cohort-description'] : '';
		    $cohort->descriptionformat = FORMAT_HTML;
		    $cohort->contextid = 1;
		    if (!empty($data['cohort-category']) && $category = $DB->get_record('course_categories', [ 'id' =>$data['cohort-category'] ] )) {
			    $cohort->contextid = context_coursecat::instance($category->id)->id;
		    }

		    $cohortId = cohort_add_cohort($cohort);
		    $this->debug("Cohort {$cohortId} with name {$cohort->name} created");
		    $wasCreated = true;

		    return $cohortId;
	    }

    	return $cohort->id;
	}

	private function debug( $message ) {
		if ($this->verbose) {
			echo $message.PHP_EOL;
		}
	}
}
