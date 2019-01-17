<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Finder\Finder;
use Sabre\Xml\Service;
use Simplon\Mysql\PDOConnector;
use Simplon\Mysql\Mysql;
use \DateTime;

/*
CREATE TABLE `crashes` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `uid` varchar(32) COLLATE utf8_unicode_ci NOT NULL DEFAULT '',
  `tstamp` int(10) UNSIGNED NOT NULL DEFAULT '0',
  `CLRVersion` varchar(30) COLLATE utf8_unicode_ci NOT NULL DEFAULT '',
  `ExceptionMessage` varchar(4096) COLLATE utf8_unicode_ci NOT NULL DEFAULT '',
  `ExceptionType` varchar(192) COLLATE utf8_unicode_ci NOT NULL DEFAULT '',
  `HostApplication` varchar(192) COLLATE utf8_unicode_ci NOT NULL DEFAULT '',
  `HostApplicationVersion` varchar(30) COLLATE utf8_unicode_ci NOT NULL DEFAULT '',
  `NBugVersion` varchar(30) COLLATE utf8_unicode_ci NOT NULL DEFAULT '',
  `TargetSite` varchar(1024) COLLATE utf8_unicode_ci NOT NULL DEFAULT '',
  `Source` varchar(256) COLLATE utf8_unicode_ci NOT NULL DEFAULT '',
  `StackTrace` text COLLATE utf8_unicode_ci,
  PRIMARY KEY (id)
) ENGINE=MyISAM DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;

CREATE TABLE `extendedinformation` (
  `pid` int(10) UNSIGNED NOT NULL,
  `ExtendedKey` varchar(192) COLLATE utf8_unicode_ci NOT NULL DEFAULT '',
  `ExtendedValue` varchar(1024) COLLATE utf8_unicode_ci NOT NULL DEFAULT ''
) ENGINE=MyISAM DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;

*/

class ImportController extends AbstractController
{
  private $crashFilePath = '/opt/lampp/htdocs/data';

   /**
    * @Route("/import")
    */
  public function importData()
  {
    $pdo = new PDOConnector(
    	'localhost', // server
    	'hschottm',      // user
    	'.haibin.',      // password
    	'crashreports'   // database
    );

    $pdoConn = $pdo->connect('utf8', []); // charset, options
    $dbConn = new Mysql($pdoConn);

    $finder = Finder::create()->files()->name('*.zip')->in($this->crashFilePath);
    $service = new Service();
    $inserted = 0;
    foreach ($finder as $file)
    {
      $zipFile = new \PhpZip\ZipFile();
      $zipFile->openFile($file->getRealPath())->extractTo('/tmp');

      // parse /tmp/Report.xml
      $report = $service->parse(file_get_contents('/tmp/Report.xml'));
      $generalinfo = $report[0];

      $uid = substr($file->getFilename(), 0, -4);
      $CLRVersion = '';
      $DateTime = '';
      $ExceptionMessage = '';
      $ExceptionType = '';
      $HostApplication = '';
      $HostApplicationVersion = '';
      $NBugVersion = '';
      $TargetSite = '';
      $ExtendedInformation = array();
      $Source = '';
      $StackTrace = '';
      foreach ($generalinfo['value'] as $element)
      {
        switch ($element['name'])
        {
          case '{}CLRVersion':
            $CLRVersion = $element['value'];
            break;
          case '{}DateTime':
            $conv = DateTime::createFromFormat('Y#m#d H#i#s', $element['value']);
            if ($conv != false)
            {
              $DateTime = $conv->getTimestamp();
            }
            else {
              $conv = DateTime::createFromFormat('d#m#Y H#i#s', $element['value']);
              if ($conv != false)
              {
                $DateTime = $conv->getTimestamp();
              }
              else {
                $parse = date_parse($element['value']);
                if ($parse != false)
                {
                  $conv = DateTime::createFromFormat('Y#m#d H#i#s', sprintf("%04d-%02d-%02d %02d:%02d:%02d", $parse['year'], $parse['month'], $parse['day'], $parse['hour'], $parse['minute'], $parse['second']));
                  if ($conv != false)
                  {
                    $DateTime = $conv->getTimestamp();
                  }
                  else {
                    $DateTime = time();
                  }
                }
                else {
                  $DateTime = time();
                }
              }
            }
            break;
          case '{}ExceptionMessage':
            $ExceptionMessage = $element['value'];
            break;
          case '{}ExceptionType':
            $ExceptionType = $element['value'];
            break;
          case '{}HostApplication':
            $HostApplication = $element['value'];
            break;
          case '{}HostApplicationVersion':
            $HostApplicationVersion = $element['value'];
            break;
          case '{}NBugVersion':
            $NBugVersion = $element['value'];
            break;
          case '{}TargetSite':
            $TargetSite = $element['value'];
            break;
        }
      }

      $exception = $service->parse(file_get_contents('/tmp/Exception.xml'));
      foreach ($exception as $element)
      {
        switch ($element['name'])
        {
          case '{}ExtendedInformation':
            foreach ($element['value'] as $extendedinfo)
            {
              $name = substr($extendedinfo['name'], 2);
              $value = $extendedinfo['value'];
              $ExtendedInformation[$name] = $value;
            }
            break;
          case '{}Source':
            $Source = $element['value'];
            break;
          case '{}StackTrace':
            $StackTrace = $element['value'];
            break;
        }
      }

      $found = $dbConn->fetchColumn('SELECT id FROM crashes WHERE uid = :uid', ['uid' => $uid]);
      if ($found == null)
      {
        $inserted++;
        $data = [
            'uid' => $uid,
            'tstamp' => $DateTime,
            'CLRVersion'  => $CLRVersion,
            'ExceptionMessage'  => $ExceptionMessage,
            'ExceptionType'  => $ExceptionType,
            'HostApplication'  => $HostApplication,
            'HostApplicationVersion'  => $HostApplicationVersion,
            'NBugVersion'  => $NBugVersion,
            'TargetSite'  => $TargetSite,
            'Source'  => $Source,
            'StackTrace'  => $StackTrace
        ];
        $id = $dbConn->insert('crashes', $data);

        foreach ($ExtendedInformation as $ekey => $evalue)
        {
          $data = [
              'pid' => $id,
              'ExtendedKey'  => $ekey,
              'ExtendedValue'  => $evalue
          ];
          $dbConn->insert('extendedinformation', $data);
        }
      }
    }
    return $this->render('bootstrap.html.twig', ['page_title' => 'Hallo Welt', 'finder' => $finder, 'inserted' => $inserted]);
  }
}
