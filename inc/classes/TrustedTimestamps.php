<?php
/********************************************************************************
*                                                                               *
*  Copyright 2015 Alexander Minges (alexander.minges@gmail.com)                 *
*  http://www.elabftw.net/                                                      *
*                                                                               *
*  Based on work by David Müller:                                               *
*  http://www.d-mueller.de/blog/dealing-with-trusted-timestamps-in-php-rfc-3161 *
*                                                                               *
********************************************************************************/

/********************************************************************************
*  This file is part of eLabFTW.                                                *
*                                                                               *
*    eLabFTW is free software: you can redistribute it and/or modify            *
*    it under the terms of the GNU Affero General Public License as             *
*    published by the Free Software Foundation, either version 3 of             *
*    the License, or (at your option) any later version.                        *
*                                                                               *
*    eLabFTW is distributed in the hope that it will be useful,                 *
*    but WITHOUT ANY WARRANTY; without even the implied                         *
*    warranty of MERCHANTABILITY or FITNESS FOR A PARTICULAR                    *
*    PURPOSE.  See the GNU Affero General Public License for more details.      *
*                                                                               *
*    You should have received a copy of the GNU Affero General Public           *
*    License along with eLabFTW.  If not, see <http://www.gnu.org/licenses/>.   *
*                                                                               *
*********************************************************************************/

namespace Elabftw\Elabftw;

use \DateTime;
use \Exception;

class TrustedTimestamps
{
    private $id;

    // a sha512 hash.pdf
    private $pdfFileName;
    // ELAB_ROOT . uploads/ . $pdfFileName
    private $pdfPath;
    // elabid-timestamped.pdf
    private $pdfRealName;

    // array with config (url, login, password, cert)
    private $stampParams = array();
    // things that get deleted with destruct method
    private $tmpfiles = array();

    private $requestfilePath;
    private $responsefilePath;

    private $binaryResponseString;
    private $responseTime;



    /**
     * Class constructor
     * It just needs the id of the experiment
     *
     */
    public function __construct($id)
    {
        // will be used in sqlUpdate()
        $this->id = $id;
        $this->generatePdf();

        // initialize with info from config
        try {
            $this->stampParams = $this->getTimestampParameters();
        } catch (Exception $e) {
            $_SESSION['errors'][] = $e->getMessage();
        }
    }

    /**
     * Class destructor
     * Deletes all temporary files created by TrustedTimestamps
     */
    public function __destruct()
    {
        foreach ($this->tmpfiles as $file) {
            unlink($file);
        }
    }

    /*
     * Generate the pdf to timestamp.
     *
     */
    private function generatePdf()
    {

        $pdf = new \Elabftw\Elabftw\MakePdf($this->id, 'experiments');
        $mpdf = new \mPDF();
        $mpdf->SetAuthor($pdf->author);
        $mpdf->SetTitle($pdf->title);
        $mpdf->SetSubject('eLabFTW pdf');
        $mpdf->SetKeywords($pdf->tags);
        $mpdf->SetCreator('www.elabftw.net');
        $mpdf->WriteHTML($pdf->content);

        $this->pdfFileName = hash("sha512", uniqid(rand(), true)) . ".pdf";
        $this->pdfPath = ELAB_ROOT . 'uploads/' . $this->pdfFileName;
        // write pdf to file
        $mpdf->Output($this->pdfPath, 'F');
    }

    /**
     * Return the needed parameters to request/verify a timestamp
     *
     * @return array<string,string>
     */
    public function getTimestampParameters()
    {
        $hash_algorithms = array('sha256', 'sha384', 'sha512');
        $crypto = new \Elabftw\Elabftw\Crypto();

        if (strlen(get_team_config('stamplogin')) > 2) {
            $login = get_team_config('stamplogin');
            $password = $crypto->decrypt(get_team_config('stamppass'));
            $provider = get_team_config('stampprovider');
            $cert = get_team_config('stampcert');
            $hash = get_team_config('stamphash');
            if (!in_array($hash, $hash_algorithms)) {
                $hash = 'sha256';
            }
        } elseif (get_config('stampshare')) {
            $login = get_config('stamplogin');
            $password = $crypto->decrypt(get_config('stamppass'));
            $provider = get_config('stampprovider');
            $cert = get_config('stampcert');
            $hash = get_config('stamphash');
            if (!in_array($hash, $hash_algorithms)) {
                $hash = 'sha256';
            }
            // otherwise assume no login or password is needed
        } else {
            throw new Exception(_('No valid credentials were found for Time Stamping.'));
        }

        return array('stamplogin' => $login,
                        'stamppassword' => $password,
                        'stampprovider' => $provider,
                        'stampcert' => $cert,
                        'hash' => $hash);
    }

    /**
     * Run OpenSSL via exec() with a provided command
     * @param string $cmd
     * @return array<string,null|array|integer>
     */
    private function runOpenSSL($cmd)
    {
        $retarray = array();
        exec("openssl " . $cmd . " 2>&1", $retarray, $retcode);

        return array("retarray" => $retarray,
                        "retcode" => $retcode);
    }

    /**
     * Create a tempfile in uploads/tmp temp path
     *
     * @param string $str Content which should be written to the newly created tempfile
     * @return string filepath of the created tempfile
     */
    private function createTempFile($str = "")
    {
        $tempfilename = tempnam(ELAB_ROOT . 'uploads/tmp', rand());

        if (!file_exists($tempfilename)) {
                    throw new Exception("Tempfile could not be created");
        }

        if (!empty($str) && !file_put_contents($tempfilename, $str)) {
                    throw new Exception("Could not write to tempfile");
        }

        array_push($this->tmpfiles, $tempfilename);

        return $tempfilename;
    }

    /**
     * Creates a Timestamp Requestfile from a filename
     */
    private function createRequestfile($pdf)
    {
        $outfilepath = $this->createTempFile();
        $cmd = "ts -query -data " . escapeshellarg($pdf) . " -cert -" . $this->stampParams['hash'] . " -no_nonce -out " . escapeshellarg($outfilepath);
        $opensslResult = $this->runOpenSSL($cmd);
        $retarray = $opensslResult['retarray'];
        $retcode = $opensslResult['retcode'];

        if ($retcode !== 0) {
            throw new Exception("OpenSSL does not seem to be installed: " . implode(", ", $retarray));
        }

        if (stripos($retarray[0], "openssl:Error") !== false) {
            throw new Exception("There was an error with OpenSSL. Is version >= 0.99 installed?: " . implode(", ", $retarray));
        }

        $this->requestfilePath = $outfilepath;
    }

    /**
     * Extracts the unix timestamp from the base64-encoded response string as returned by signRequestfile
     *
     */
    private function setResponseTime()
    {
        if (!is_readable($this->responsefilePath)) {
            throw new Exception('Bad token');
        }

        $cmd = "ts -reply -in " . escapeshellarg($this->responsefilePath) . " -text";
        $opensslResult = $this->runOpenSSL($cmd);
        $retarray = $opensslResult['retarray'];
        $retcode = $opensslResult['retcode'];

        if ($retcode !== 0) {
            throw new Exception("The reply failed: " . implode(", ", $retarray));
        }

        $matches = array();

        /*
         * Format of answer:
         *
         * Status info:
         *   Status: Granted.
         *   Status description: unspecified
         *   Failure info: unspecified
         *
         *   TST info:
         *   Version: 1
         *   Policy OID: 1.3.6.1.4.1.15819.5.2.2
         *   Hash Algorithm: sha256
         *   Message data:
         *       0000 - 3a 9a 6c 32 12 7f b0 c7-cd e0 c9 9e e2 66 be a9   :.l2.........f..
         *       0010 - 20 b9 b1 83 3d b1 7c 16-e4 ac b0 5f 43 bc 40 eb    ...=.|...._C.@.
         *   Serial number: 0xA7452417D851301981FA9A7CC2CF776B5934D3E5
         *   Time stamp: Apr 27 13:37:34.363 2015 GMT
         *   Accuracy: unspecified seconds, 0x01 millis, unspecified micros
         *   Ordering: yes
         *   Nonce: unspecified
         *   TSA: DirName:/CN=Universign Timestamping Unit 012/OU=0002 43912916400026/O=Cryptolog International/C=FR
         *   Extensions:
         */

        if (!is_array($retarray)) {
            throw new Exception('$retarray must be an array.');
        }

        // loop each line to find the Time stamp line
        foreach ($retarray as $retline) {
            if (preg_match("~^Time\sstamp\:\s(.*)~", $retline, $matches)) {
                // try to automatically convert time to unique unix timestamp
                $responseTime = strtotime($matches[1]);
                // workaround for faulty php strtotime function, that does not handle times in format "Feb 25 23:29:13.331 2015 GMT"
                // currently this accounts for the format used presumably by Universign.eu
                if (!$responseTime) {
                    $date = DateTime::createFromFormat("M j H:i:s.u Y T", $matches[1]);
                    if ($date) {
                        // Return formatted time as this is, what we will store in the database.
                        // PHP will take care of correct timezone conversions (if configured correctly)
                        $this->responseTime = date("Y-m-d H:i:s", $date->getTimestamp());
                    } else {
                        throw new Exception('Could not get response time!');
                    }
                }
                break;
            }
        }
    }

    /*
     * Contact the TSA and receive a token after successful timestamp
     *
     */
    private function postData()
    {
        $ch = curl_init();
        // set url of TSA
        curl_setopt($ch, CURLOPT_URL, $this->stampParams['stampprovider']);
        // if login and password are set, pass them to curl
        if (!is_null($this->stampParams['stamplogin']) && !is_null($this->stampParams['stamppassword'])) {
            curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
            curl_setopt($ch, CURLOPT_USERPWD, $this->stampParams['stamplogin'] . ":" . $this->stampParams['stamppassword']);
        }
        // add proxy if there is one
        if (strlen(get_config('proxy')) > 0) {
            curl_setopt($ch, CURLOPT_PROXY, get_config('proxy'));
        }
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_BINARYTRANSFER, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, file_get_contents($this->requestfilePath));
        curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/timestamp-query'));
        curl_setopt($ch, CURLOPT_USERAGENT, "eLabFTW");
        $binaryResponseString = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($status != 200 || !strlen($binaryResponseString)) {
            // check if we got something bad
            throw new Exception('Bad answer from TSA (' . $status . ')<br>' . $binaryResponseString);
        }

        // populate variable
        $this->binaryResponseString = $binaryResponseString;
    }

    /*
     * Save the binaryResponseString to a .asn1 file (token)
     *
     */
    private function saveToken()
    {
        $longname = hash("sha512", uniqid(rand(), true)) . ".asn1";
        $file_path = ELAB_ROOT . 'uploads/' . $longname;
        if (!file_put_contents($file_path, $this->binaryResponseString)) {
            throw new Exception('Cannot save token to disk!');
        }
        $this->responsefilePath = $file_path;
    }

    /**
     * Validates a file against its timestamp and optionally check a provided time for consistence with the time encoded
     * in the timestamp itself.
     *
     * @return bool
     */
    private function validate()
    {
        $cmd = "ts -verify -data " . escapeshellarg($this->pdfPath) . " -in " . escapeshellarg($this->responsefilePath) . " -CAfile " . escapeshellarg(ELAB_ROOT . $this->stampParams['stampcert']);

        $opensslResult = $this->runOpenSSL($cmd);
        $retarray = $opensslResult['retarray'];
        $retcode = $opensslResult['retcode'];

        /*
         * just 2 "normal" cases:
         *  1) Everything okay -> retcode 0 + retarray[0] == "Verification: OK"
         *  2) Hash is wrong -> retcode 1 + strpos(retarray[somewhere], "message imprint mismatch") !== false
         *
         * every other case (Certificate not found / invalid / openssl is not installed / ts command not known)
         * are being handled the same way -> retcode 1 + any retarray NOT containing "message imprint mismatch"
         */

        if ($retcode === 0 && strtolower(trim($retarray[0])) == "verification: ok") {

            /*
            if (!is_null($timeToCheck)) {
                if ($timeToCheck != $this->responseTime) {
                    throw new Exception("The response time of the request was changed");
                }
            }
             */
            return true;
        }

        if (!is_array($retarray)) {
            throw new Exception('$retarray must be an array.');
        }

        foreach ($retarray as $retline) {
            if (stripos($retline, "message imprint mismatch") !== false) {
                            return false;
            }
        }

        throw new Exception("System command failed: " . implode(", ", $retarray));
    }

    /*
     * Update SQL
     *
     */
    private function sqlUpdateExperiment()
    {
        global $pdo;

        $sql = "UPDATE experiments SET timestamped = 1, timestampedby = :userid, timestampedwhen = :timestampedwhen, timestamptoken = :longname WHERE id = :id;";
        $req = $pdo->prepare($sql);
        $req->bindParam(':timestampedwhen', $this->responseTime);
        // the date recorded in the db has to match the creation time of the timestamp token
        $req->bindParam(':longname', $this->responsefilePath);
        $req->bindParam(':userid', $_SESSION['userid']);
        $req->bindParam(':id', $this->id);
        if (!$req->execute()) {
            throw new Exception('Cannot update SQL!');
        }
    }

    /*
     * The realname is elabid-timestamped.pdf
     *
     */
    private function setPdfRealName()
    {
        global $pdo;

        $sql = "SELECT elabid FROM experiments WHERE id = :id";
        $req = $pdo->prepare($sql);
        $req->bindParam(':id', $this->id);
        if (!$req->execute()) {
            throw new Exception('Cannot get elabid!');
        }
        $this->pdfRealName = $req->fetch(\PDO::FETCH_COLUMN) . "-timestamped.pdf";
    }

    /*
     * Add also our pdf to the attached files of the experiment, this way it is kept safely :)
     * I had this idea when realizing that if you comment an experiment, the hash won't be good anymore. Because the pdf will contain the new comments.
     * Keeping the pdf here is the best way to go, as this leaves room to leave comments.
     */
    private function sqlInsertPdf()
    {
        global $pdo;

        $md5 = hash_file('md5', $this->pdfPath);

        // DA REAL SQL
        $sql = "INSERT INTO uploads(real_name, long_name, comment, item_id, userid, type, md5) VALUES(:real_name, :long_name, :comment, :item_id, :userid, :type, :md5)";
        $req = $pdo->prepare($sql);
        $req->bindParam(':real_name', $this->pdfRealName);
        $req->bindParam(':long_name', $this->pdfFileName);
        $req->bindValue(':comment', "Timestamped PDF");
        $req->bindParam(':item_id', $this->id);
        $req->bindParam(':userid', $_SESSION['userid']);
        $req->bindValue(':type', 'exp-pdf-timestamp');
        $req->bindParam(':md5', $md5);
        if (!$req->execute()) {
            throw new Exception('Cannot insert into SQL!');
        }
    }
    /*
     * The main function.
     * Request a timestamp and parse the response.
     *
     * @return bool True upon timestamping success, throw Exceptions in your face if it fails
     */
    public function timeStamp()
    {
        // first we create the request file
        $this->createRequestfile($this->pdfPath);

        // make the request to the TSA
        $this->postData();

        // save the token to .asn1 file
        $this->saveToken();

        // set the responseTime
        $this->setResponseTime();

        // validate everything so we are sure it is OK
        $this->validate();

        // SQL
        $this->sqlUpdateExperiment();
        $this->setPdfRealName();
        $this->sqlInsertPdf();

        return true;
    }
}
