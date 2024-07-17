<?php
declare(ticks = 1);
namespace aisQlib;
require 'vendor/autoload.php';

use mysqli_sql_exception;
use \Prewk\XmlStringStreamer;
use DateTime;
use mysqli;


class getVessels
{
	private string $base = "/home/aisq/web/get.aisq.live/public_html/";
	private int $runcount = 0;
	private DateTime $starttime, $finishtime;
	private int $count = 0;
	private int $errorcount = 0;
	private int $inserted = 0;
	private int $updated = 0;
	private int $updatecount = 0;
	private int $totaltime = 0;


	/**
	 * signal handlers
	 * @param string $signo
	 * @return void
	 */
	function sig_handler(string $signo)
	{
		switch ($signo) {
			case SIGTERM:
				// handle shutdown tasks
				$this->shutdown();
				exit;
			case SIGHUP:
				// handle restart tasks
				break;
			case SIGINT:
				$this->shutdown();
				exit;
			default:
				// handle all other signals
		}
	}
	
	/**
	 * On shutdown, collect stats and print return.	
	 * @return void
	 */
	public function shutdown()
	{
		system("clear");
		$return = "";
		$select = $this->dbcon();
		$this->finishtime = new DateTime();
		$this->finishtime->setTimezone(timezone_open("America/Chicago"));
		$sqlstring = "UPDATE site SET `finishtime`='" . $this->finishtime->getTimestamp() . "', `activeupdate`=false";
		$select->query($sqlstring);
		$info = $select->query("SELECT * FROM site");
		$info = $info->fetch_assoc();
		$this->totaltime = $this->finishtime->getTimestamp()-$this->starttime->getTimestamp();
		$tt = new DateTime();
		$tt->setTimestamp($this->totaltime);
		$tt->setTimezone(timezone_open("America/Chicago"));
		$select->close();
		$return .= "\n{\"RECORDS\": \"" . (string)$this->count . "\",\n";
		$return .= "\"TIME STARTED\": \"" . (string)date_format($this->starttime, 'm-j-Y G:i:s') . "\",\n";
		$return .= "\"TIME FINISHED\": \"" . (string)date_format($this->finishtime, 'm-j-Y G:i:s') . "\",\n";
		$return .= "\"TOTAL TIME\": \"" . $tt->format("I:s") . "\",\n";
		$return .= "\"ERROR COUNT\": \"" . (string)$this->errorcount . "\",\n";
		$return .= "\"INSERTED\": \"" . (string)$this->inserted . "\",\n";
		$return .= "\"UPDATED\": \"" . (string)$this->updated . "\"}";
		echo $return;
		
		echo "\nIt took " . $tt->format('I:s') . " minutes to complete operation.\n";
	}
	/**
	 *  Set start time for time tracking.
	 * @return void
	 */
	public function __construct()
	{
		$this->starttime = new DateTime;
		pcntl_signal(SIGTERM, [$this, "sig_handler"]);
		pcntl_signal(SIGHUP,  [$this, "sig_handler"]);
		pcntl_signal(SIGINT, [$this, "sig_handler"]);

	}
	
	/**
	 * Connects to aisq database and returns mysqli object.
	 * @return mysqli
	 */
	public function dbcon(): mysqli
	{
		$db = array(
			'server' => '156.67.74.151',
			'username' => 'u937626803_aisq',
			'password' => 'L4wr4m41987!',
			'database' => 'u937626803_aisq'
		);
		return new mysqli($db['server'], $db['username'], $db['password'], $db['database']);
	}

	/**
     * Returns true if $string is empty. $string is not empty if it includes '', "", *space* or any forms of space. Returns false if string is not empty.
     * @param mixed $string
     * @return bool
     */
    function isempty(mixed $string): bool{
        $val = preg_replace('#[^A-Za-z\d]+#', '', $string) ;
        $val = trim($val, '');
        return $val=='';
    }

	/**
	 * Opens $url and writes output to "vesseldata.zip" for processing. Returns true if successful or false if not successful.
	 * @param string $url
	 * @return bool
	 */
	public function openURL(string $url): bool
	{
		$destination_path = $this->base . "vesseldata.zip";
		$ch = curl_init($url);
		$fp = fopen($destination_path, "w+");
		curl_setopt($ch,CURLOPT_RETURNTRANSFER, true);
        $output = curl_exec($ch);
        $st_code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        fwrite($fp, $output);
        fclose($fp);

		if($st_code == 200){
			return true;
		}else{
			return false;
		}
	}

	/**
	 * Processes vesseldata.zip and extracts file to same directory as vesseldata.zip. Returns true if successful.
	 * @return bool
	 */
	public function processZip(): bool
    {
		$zip = new \PhpZip\ZipFile;
		$zip->openFile($this->base . "vesseldata.zip");
		if($zip->extractTo($this->base))
		{
			$zip->close();
			return true;
		}else{
			$zip->close();
			return false;
		}
	}

	/**
	 * Logs $errormsg into errlog.log.
	 * @param string $errormsg
	 * @return bool
	 */
	public function logerror(string $errormsg): bool
    {
		$file = fopen($this->base . "errlog.log", "a+");
        $d = new DateTime();
		date_timezone_set($d,timezone_open("America/Chicago"));
		if(fwrite($file, $d->format('m-j-Y G:i:s') . ": " . $errormsg. "\n"))
		{
			fclose($file);
			return true;
		}else{
			fclose($file);
			return false;
		}
	}

	/**
	 * Constructs aishub.net's url and opens. Processes zip. If successful, iterates data.xml for each vessel and inserts or updates vessel in database. Returns information about whole process. if $dryrun is true, 
	 * function will go through the whole process except request xml data from aishub. If $test is true, function will only process $testcount number of vessels.
	 * @param bool $dryrun
	 * @param bool $test
	 * @param int $testcount
	 * @return string
	 */
	public function getVessels(bool $dryrun = false, bool $test = false, int $testcount = 10): string
	{
		$url = "https://data.aishub.net/ws.php";
		$A = "AH_3457_E62D10AB";
		$B = 1; //0 = AIS format; 1 = human readable
		$C = "xml"; //output format (xml, json, csv)
		$D = 1; //compress 0 – no compression, 1 – ZIP, 2 – GZIP, 3 – BZIP2
		$E = - 90; //South - minimum latitude
		$F = 90; //North - maximum latitude
		$G = - 180; //West - minimum longitude
		$H = 180; //East - maximum longitude
		$wholeurl = $url . "?username=" . $A . "&format=" . $B . "&output=" . $C . "&compress=" . $D . "&latmin=" . $E . "&latmax=" . $F . "&lonmin=" . $G . "&lonmax=" . $H;

		if(!$dryrun)
		{
			$this->openURL($wholeurl);
		}

		if($this->processZip())
		{
			$this->count = 0;
			$this->inserted = 0;
			$this->errorcount = 0;
			$this->updated = 0;
			$select = $this->dbcon();
			$streamer = XmlStringStreamer::createStringWalkerParser($this->base . "data.xml");
			$this->starttime = new DateTime();
			$this->starttime->setTimezone(timezone_open("America/Chicago"));

			$select->query("UPDATE site SET activeupdate=true, starttime=" . $this->starttime->getTimestamp());
			$ani = 0;
			while ($node = $streamer->getNode())
			{
				if($test == true && $this->count == $testcount)
				{
					break;
				}
				$vessel = simplexml_load_string($node);
				libxml_use_internal_errors(false);
				$this->count++;
				if($vessel != false){
					if($vessel->getName()!="vessel")
					{
						if($vessel->getName() == "ERROR_MESSAGE")
						{
							$this->errorcount++;
							echo "\nERROR: " . $vessel[0] . "\n";
							$this->logerror("\nERROR: " . $vessel[0] . "\n");
						}
					}else{
						if($ani == 0)
						{
							$ani = 1;
						}else{
							$ani = 0;
						}
						$qTime = mysqli_escape_string($select, $vessel['TIME']);
						$qLongitude = (float)$vessel['LONGITUDE'];
						$qLatitude = (float)$vessel['LATITUDE'];
						$qCOG = (float)$vessel['COG'];
						$qSOG = (float)$vessel['SOG'];
						$qROT = (float)$vessel['ROT'];
						$qHeading = (float)$vessel['HEADING'];
						$qNavstat = (string)mysqli_escape_string($select,$vessel['NAVSTAT']);
						$qIMO = (string)mysqli_escape_string($select,$vessel['IMO']);
						$qName = (string)mysqli_escape_string($select,$vessel['NAME']);
						$qCallsign = (string)mysqli_escape_string($select,$vessel['CALLSIGN']);
						$qType = (string)mysqli_escape_string($select,$vessel['TYPE']);
						$qA = (float)$vessel['A'];
						$qB = (float)$vessel['B'];
						$qC = (float)$vessel['C'];
						$qD = (float)$vessel['D'];
						$qDraught = (float)$vessel['DRAUGHT'];
						$qDest = (string)mysqli_escape_string($select,$vessel['DEST']);
						$qETA = (string)mysqli_escape_string($select,$vessel['ETA']);
						$qMMSI = (string)mysqli_escape_string($select,$vessel['MMSI']);

						$sql = "SELECT * FROM `vessel` WHERE mmsi='" . $qMMSI . "'";
						$result = $select->query($sql);
						if ($result->num_rows > 0)
						{
							$settime = $result->fetch_assoc()['time'];
							$newtime = $qTime;
							if($newtime != $settime)
							{
								system("clear");
								if($ani == 0)
								{
									echo "-U-";
								}else{
									echo "\U/";
								}
								$sqlstring = "UPDATE `vessel` SET `time`='" . $qTime . "',`long`='" . $qLongitude . "',`lat`='" . $qLatitude . "',`cog`='" . $qCOG . "',`sog`='" . $qSOG . "',`rot`='" . $qROT . "',`heading`='" . $qHeading . "',`navstat`='" . $qNavstat . "',`imo`='" . $qIMO . "',`name`='" . $qName . "',`callsign`='" . $qCallsign . "',`type`='" . $qType . "',`a`='" . $qA . "',`b`='" . $qB . "',`c`='" . $qC . "',`d`='" . $qD . "',`draught`='" . $qDraught . "',`dest`='" . $qDest . "',`eta`='" . $qETA . "' WHERE `mmsi`='" . $qMMSI . "'";
								$result = $select->query($sqlstring);
								if(!$result){
									$this->errorcount++;
									$resulterror = new mysqli_sql_exception();
									$this->logerror($resulterror->getMessage());
								}else{
									$this->updated++;
								}
							}else{
							}
						} else {
							system("clear");
							if($ani == 0)
							{
								echo "-I-";
							}else{
								echo "\I/";
							}
							$sqlstring = "INSERT INTO vessel (`time`, `long`, `lat`, `cog`, `sog`, `rot`, `heading`, `navstat`, `imo`, `name`, `callsign`, `type`, `a`, `b`, `c`, `d`, `draught`, `dest`, `eta`, `mmsi`) VALUES ('" . $qTime . "','" . $qLongitude . "','" . $qLatitude . "','" . $qCOG . "','" . $qSOG . "','" . $qROT . "','" . $qHeading . "','" . $qNavstat . "','" . $qIMO . "', '" . $qName . "','" . $qCallsign . "','" . $qType . "','" . $qA . "','" . $qB . "','" . $qC . "','" . $qD . "','" . $qDraught . "','" . $qDest . "','" . $qETA . "', '" . $qMMSI . "')";
							$result = $select->query($sqlstring);
							if(!$result)
							{
								$this->errorcount++;
								$resulterror = new mysqli_sql_exception();
								$this->logerror($resulterror->getMessage());
							}else{
								$this->inserted++;
							}
						}
						echo "\n" . $this->count;
					}
				}
			}

			$this->finishtime = new DateTime();
			$this->finishtime->setTimezone(timezone_open("America/Chicago"));
			$sqlstring = "UPDATE site SET `finishtime`='" . $this->finishtime->getTimestamp() . "', `activeupdate`=false";
			$select->query($sqlstring);
			$info = $select->query("SELECT * FROM site");
			$info = $info->fetch_assoc();
			$this->updatecount = $info['updatecount'] + 1;
			$select->query("UPDATE site SET `updatecount`=" . $this->updatecount);
			$totaltime = (int)$this->finishtime->getTimestamp()-$this->starttime->getTimestamp();
			$tt = new DateTime();
			$tt->setTimestamp($totaltime);
			$tt->setTimezone(timezone_open("America/Chicago"));
			$select->query("UPDATE site SET `allTimeErrorCount`=" . $info['allTimeErrorCount'] + $this->errorcount . ", `allTimeInserted`=" . $info['allTimeInserted'] +  $this->inserted . ", `allTimeTotalTime`=" . $info['allTimeTotalTime'] + $this->totaltime . ", `allTimeUpdated`=" . $info['allTimeUpdated'] + $this->updated);

			$select->close();
			$return = "";
			if($this->errorcount > 0)
			{
				$return = "\nPlease check error log.\n";
			}
			$return .= "\n{\"RECORDS\": \"" . (string)$this->count . "\",\n";
			$return .= "\"TIME STARTED\": \"" . (string)date_format($this->starttime, 'm-j-Y G:i:s') . "\",\n";
			$return .= "\"TIME FINISHED\": \"" . (string)date_format($this->finishtime, 'm-j-Y G:i:s') . "\",\n";
			$return .= "\"TOTAL TIME\": \"" . $tt->format("I:s") . "\",\n";
			$return .= "\"ERROR COUNT\": \"" . (string)$this->errorcount . "\",\n";
			$return .= "\"INSERTED\": \"" . (string)$this->inserted . "\",\n";
			$return .= "\"UPDATED\": \"" . (string)$this->updated . "\"}";
			echo $return;
			
			$this->runcount++;

			echo "\nIt took " . $tt->format('I:s') . " minutes to complete operation.\nDatabase updated " . $this->runcount . " times.\n";
			//return true;
			if($this->errorcount > 0)
			{
				for ($i=60; $i > 0; $i--) { 
					system("clear");
					echo "Please wait " . $i . " seconds...\n";
					sleep(1);
				}
			}
			$this->getVessels();
		}else{
			$this->logerror("Unzip failed");
			echo "Unzip failed";
			return false;
		}
	}

	/**
	 * Returns log or false if log cannot be opened/doesn't exist.
	 * @return string | bool
	 */
	public function viewLog() : string | bool
	{
		if(file_exists("errlog.log"))
		{
			$file = fopen("errlog.log", "r");
			$log = fread($file, filesize('errlog.log'));
			fclose($file);
			return $log;
		}else{
			$file = fopen('errlog.log','w+');
			fwrite($file,'');
			fclose($file);
			return false;
		}
	}

	/**
	 * Clears log.
	 * @return void
	 */
	public function clearLog() : void
	{
		if(file_exists("errlog.log"))
		{
			$file = fopen("errlog.log","w+");
			fwrite($file, '');
			fclose($file);
		}
	}
}

