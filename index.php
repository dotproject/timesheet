<?php 
$timesheet_id = isset($_GET['timesheet_id']) ? $_GET['timesheet_id'] : 0; 
 
// check permissions 
$denyRead = getDenyRead( $m, $timesheet_id ); 
$denyEdit = getDenyEdit( $m, $timesheet_id ); 
 
if ($denyRead) { 
	$AppUI->redirect( "m=help&a=access_denied" );
}
$AppUI->savePlace();

require_once( "./classes/date.class.php" );
$df = $AppUI->getPref( 'SHDATEFORMAT' );
$rollover_day = LOCALE_FIRST_DAY; // set the start of the week.  1 for monday, 0 for sunday.

#################################### [ view section UI code ] ########################

/**
* Gets the start of the week based on date passed
* @param $date date to find start of week
* @param $rollover_day day on which week starts.  1 for monday, 0 for sunday.
* @return object Date the new Date object
*/
function getStartOfWeek($date, $rollover_day) {
	$today_weekday = $date -> getDayOfWeek();

	$new_start_offset = $rollover_day - (int)$today_weekday;
	$date -> subtractSeconds($new_start_offset * 24 * 60 * 60 * -1);
	
	return $date;
}

/**
* Convert hours to time, add leading zeroes.
* @param $hours hours to convert to time
* @return string of the hours and minutes (00:00)
*/
function hoursToTime($total_hours) {
	
	//since we're trying to convert an unknown float to an integer
	//we need to round number to a safe amount of decimal digits
	//to avoid floating point arithmetic mishaps.  In particular
	//without rounding intval( 1.9999... ) gives 1
	//supposedly 7 is a safe number of digits(?) -- it will allow
	//precision of up to 0.000006 minutes (0.00036 sec)
	$total_hours = round( $total_hours, 7 );
	$hours = intval( $total_hours );

	$minutes = (($total_hours - $hours) * 60);
	$time['hour'] = $hours;
	$time['minute'] = $minutes;
	
	return padTime( (object) $time );
}

function splitTime( $time ) {
    $hhmm = explode( ":", $time );
    
    if ( count($hhmm) > 1 ) {
        $hhmm['hour'] = $hhmm[0];
        $hhmm['minute'] = $hhmm[1];
    }
     
    return (object) $hhmm;
}

function padTime( $time ) {
    return sprintf( "%02.0f:%02.0f", $time->hour, $time->minute );
}

/**
* truncate() Simple function to shorten a string and add an ellipsis
* 
* @param string $string Origonal string
* @param integer $max Maximum length
* @param string $rep Replace with... (Default = '' - No elipsis -)
* @return string
* @author David Duong and Alex :)
**/
function truncate ($string, $max = 50, $rep = '') {
	if ( strlen($string) < $max + strlen($rep) ) {
		return $string;
	} else {
		$leave = $max - strlen ($rep);
		return substr_replace($string, $rep, $leave);
	}
} 

?>
<script language="JavaScript" type="text/javascript">
function sendIt() {
	if (confirm( "Is all the information and time on this timesheet correct and accurate?\n" )) {
		var form = document.TIDedit;
		form.submit();
	} 
}
</script>
<table width="98%" border="0" cellpadding="0" cellspacing="2"> 
<tr> 
	<td><img src="./images/icons/projects.gif" alt="" border="0" width=42 height=42></td> 
	<td nowrap>
		<span class="title"><?php echo $AppUI->_( 'Timesheet' );?></span>
	</td> 
	<td align="right" width="100%"></td> 
	<td nowrap="nowrap" width="20" align="right"><?php echo contextHelp( '<img src="./images/obj/help.gif" width="14" height="16" border="0" alt="'.$AppUI->_( 'Help' ).'">' );?></td> 
</tr> 
</table>

<!-- ################# Bad HTML Code -->
<?php

//get week to be displayed (#weeks ago current week being 0)
if (isset( $_GET['wk'] ) ) {
	$AppUI->setState( 'TmsWk', $_GET['wk'] );
}
$wk = $AppUI->getState( 'TmsWk') !== NULL && $wk >= 0 ? $AppUI->getState( 'TmsWk' ) : 0;

if ( $wk == 0 )
	$week_text = $AppUI->_("Current week");
else if ( $wk == 1 )
	$week_text = $AppUI->_("Last week");
else
	$week_text = "$wk " . $AppUI->_("weeks ago");

if (isset( $_GET['tab'] )) {
	$AppUI->setState( 'TmsVwTab', $_GET['tab'] );
}
$tab = $AppUI->getState( 'TmsVwTab' ) !== NULL ? $AppUI->getState( 'TmsVwTab' ) : 0;

$tabBox = new CTabBox( "?m=timesheet", "./modules/timesheet/", $tab );
$tabBox->add( 'vw_general', 'General' );
$tabBox->add( 'vw_tasks_todo', 'Tasks' );		
$tabBox->show();

?>


<form name="TIDedit" action="./index.php?m=timesheet&a=dosql" method="post"> 

<table width="100%" border="0" bgcolor="#f4efe3" cellpadding="3" cellspacing="1" class="tbl"> 
<tr>
	<th norap="nowrap" colspan="7"><?php echo $week_text ?></th>
</tr>
<tr>
	<th nowrap="nowrap"><?php echo $AppUI->_('Date') ?></th>
	<th nowrap="nowrap"><?php echo $AppUI->_('In') ?></th>
	<th nowrap="nowrap"><?php echo $AppUI->_('Out') ?></th>
	<th nowrap="nowrap"><?php echo $AppUI->_('Break') ?></th>
	<th nowrap="nowrap"><?php echo $AppUI->_('Total') ?></th>
	<th nowrap="nowrap"><?php echo $AppUI->_('Total Logged') ?></th>
	<th nowrap="nowrap" width="65%"> <br></th>
</tr>
 
<?php

$time_set = new CDate ();
$time_set->addDays( -7 * $wk );
$time_set = getStartOfWeek($time_set, $rollover_day);
$date_start = $time_set -> format("%Y-%m-%d");
$total_time_worked = new CDate('0000-00-00 00:00:00');
$total_hours_logged = 0;

// loop through the week
for ($day = 0; $day < 7; $day++) {
	
$date_temp = $time_set -> format("%Y-%m-%d");

//pull data for this timesheet
$timesheet_sql = "

SELECT timesheet.* 
from timesheet
WHERE timesheet_date = '$date_temp'
AND user_id = $AppUI->user_id
";

$result = db_exec ( $timesheet_sql );
$timesheet_row = db_fetch_assoc ( $result );


$log_sql = "
SELECT task_log.*, user_username, tasks.task_name, tasks.task_id, tasks.task_description, projects.project_short_name, projects.project_id, projects.project_description, companies.company_name
FROM task_log
LEFT JOIN users ON user_id = task_log_creator
LEFT JOIN tasks ON task_id = task_log_task
LEFT JOIN projects ON project_id = task_project
LEFT JOIN companies ON company_id = project_company
WHERE task_log_date = '$date_temp'and user_id = $AppUI->user_id
ORDER BY task_log_date
";

$logs = db_loadList( $log_sql );
$s = '';
$logged_hours = 0;

foreach ($logs as $log_row) {
	$logged_hours += $log_row["task_log_hours"];
	$s .= "<tr>";
	$s .= "\n\t\t<td><a href=\"?m=tasks&a=view&task_id=${log_row['task_log_task']}&tab=1&task_log_id=".@$log_row['task_log_id'].'" style="cursor: hand;" title="edit log">'
			. "\n\t\t\t". dPshowImage( './images/icons/stock_edit-16.png', 16, 16, 'edit log' )
			. "\n\t\t</a></td>";
	$s .= "<td nowrap=\"nowrap\" align=\"left\"><a href=\"index.php?m=projects&a=view&project_id=${log_row['project_id']}\" title=\"${log_row['project_description']}\">${log_row['project_short_name']}</a> -> ";
	$s .= "<a href=\"index.php?m=tasks&a=view&task_id=${log_row['task_id']}\" title=\"${log_row['task_description']}\">" . truncate($log_row["task_name"], 30, '...') . "</a> -> " . truncate($log_row["task_log_name"], 30, '...') . "</td>\n\t";
	$s .= '<td nowrap="nowrap" class="numerical">' . sprintf("%.2f", $log_row["task_log_hours"]) . "</td>\n";
	$s .= '</tr>';
}

?> 
<tr> 
	<td nowrap><?php 
		echo $time_set->format("%A %b %e, %Y");
		echo '<input type="hidden" name="timesheet_date[]" value="' . ($timesheet_row["timesheet_date"] ? $timesheet_row["timesheet_date"] : $time_set->format("%Y-%m-%d")) . '">';
		?>
		<input name="timesheet_id[]" value="<?php echo $timesheet_row["timesheet_id"] ?>" type="hidden">
	</td> 
	<td nowrap><input type="text" size="5" name="timesheet_time_in[]" value="<?php 
		if (db_num_rows($result)) {
			$timeIn = splitTime( $timesheet_row["timesheet_time_in"] );
			if (intval($timeIn->hour) or intval($timeIn->minute)) echo "$timeIn->hour:$timeIn->minute";
		}
		?>">
	</td> 
	<td nowrap><input type="text" size="5" name="timesheet_time_out[]" value="<?php 
		if (db_num_rows($result)) {
			$timeOut = splitTime( $timesheet_row["timesheet_time_out"] );
			if (intval($timeOut->hour) or intval($timeOut->minute)) echo "$timeOut->hour:$timeOut->minute";
		}
		?>">
	</td> 
	<td nowrap><input type="text" size="5" name="timesheet_time_break[]" value="<?php 
		if (db_num_rows($result)) {
			$timeBreak = splitTime( $timesheet_row["timesheet_time_break"] );
			if (intval($timeBreak->hour) or intval($timeBreak->minute)) echo "$timeBreak->hour:$timeBreak->minute";
		}
		?>">
	</td> 
	<td nowrap align="center"><?php 
		if ((intval($timeOut->hour) or intval($timeOut->minute)) and (intval($timeIn->hour) or intval($timeIn->minute))) {
			
			$minutes_daily = ($timeOut->hour - $timeIn->hour - $timeBreak->hour)*60 + ($timeOut->minute - $timeIn->minute - $timeBreak->minute);
			
			$time_daily->hour = (int)( $minutes_daily / 60 );
			$time_daily->minute = $minutes_daily % 60;
			
			//echo $timeOut->format("%H:%M");
			echo padTime( $time_daily );
		} else echo '--';
		?>
	</td>
	<td nowrap align="center">
	<?php 
		if ($logged_hours) {
			// convert hours logged to time logged...
			echo hoursToTime($logged_hours);
		} else echo '--';
	?>
	</td>
	<td nowrap align="center">
		<table border="0" bgcolor="#f4efe3" cellpadding="3" cellspacing="1" class="tbl" width="100%">
		<tr>
			<td colspan="2" nowrap="nowrap" width="100%"><b>Task</b></td>
			<td nowrap="nowrap"><b>Hours</b></td>
		</tr>
		<?php echo $s ?>
		</table>
	
	</td>
</tr> 
<?php 
// total the total hours
$time_set -> addSeconds(24 * 60 * 60);
//$total_time_worked->addSeconds($timeOut->hour * 60 * 60 + $timeOut->minute * 60);
$total_time_worked->hour += $time_daily->hour + floor( ($total_time_worked->minute + $time_daily->minute) / 60 );
$total_time_worked->minute = ( $total_time_worked->minute + $time_daily->minute ) % 60;
$total_hours_logged += $logged_hours;

// reset times
$timeIn = null;
$timeOut = null;
$timeBreak = null;
$time_daily = null;
}
?>
<tr>
	<td nowrap><br></td>
	<td nowrap align="left" colspan="3"><b>Total:</b></td>
	<td nowrap align="center"><b><?php echo padTime( $total_time_worked ); ?></b></td>
	<td nowrap align="center"><b><?php echo hoursToTime($total_hours_logged); ?></b></td>
	<td nowrap align="right"><input type="submit" name="update" value="Update" class="button"></td>
</tr>
<?php
if ($tg_data['tt_active'] > 0 ) { 
?>
<tr> 
	<td colspan="8" align="right">
	<a href="index.php?m=timesheet&a=addedit&timesheet_id=<?php echo $timesheet_id;?>&addrow=1&tid=0">Add New Row</a>
	</td>
</tr> 
<?php 
}
if ($tg_data['tt_active'] > 0 ) { 
?>
<tr bgcolor="#FFFFFF">
	<td colspan="8" bgcolor="#FFFFFF" align="right">
	<input class="button" type="Button" value="Send In" onClick="sendIt();">
	</td>
</tr>
<?php } ?>
</table> 
</form>