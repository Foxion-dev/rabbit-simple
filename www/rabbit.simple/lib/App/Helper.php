<?php
namespace App;
class Helper
{
    public static function logger($message, $dir = '')
    {
        if (empty($dir)) $dir = $_SERVER['DOCUMENT_ROOT'];

        $log_dirname = $dir . '/logs';

        $bt = debug_backtrace();
        $bt = $bt[0];
        $dRoot = $_SERVER["DOCUMENT_ROOT"];
        $dRoot = str_replace("/", "\\", $dRoot);
        $bt["file"] = str_replace($dRoot, "", $bt["file"]);
        $dRoot = str_replace("\\", "/", $dRoot);
        $bt["file"] = str_replace($dRoot, "", $bt["file"]);

        if (!file_exists($log_dirname)) {
            mkdir($log_dirname, 0777, true);
        }
        $log_file_data = $log_dirname . '/log_' . date('d-M-Y') . '.log';
        file_put_contents($log_file_data, 'File: ' . $bt["file"] . ' [' . $bt["line"] . '] ' . "\n" . date("H:i:s") . ' - ' . $message . "\n", FILE_APPEND);

        //TODO Скрипт удаления лишних логов и файлов с лидами

        $dirLog = $log_dirname;
        $filesAndDirs = scandir($dirLog);

        $date6days = date("d-M-Y", time() - 86400 * 6);
        $date5days = date("d-M-Y", time() - 86400 * 5);
        $date4days = date("d-M-Y", time() - 86400 * 4);
        $date3days = date("d-M-Y", time() - 86400 * 3);
        $date2days = date("d-M-Y", time() - 86400 * 2);
        $dateYesterday = date("d-M-Y", time() - 86400);
        $dateToday = date("d-M-Y", time());

        $resultFilesArray = [];

        if ($filesAndDirs) {

            // Получим все наши файлы логов и лидов в один массив
            foreach ($filesAndDirs as $file) {
                $resultFilesArray[] = $dirLog . '/' . $file;
            }

            // Удалим лишние файлы, при этом пропуская нужные нам
            foreach ($resultFilesArray as $delFile) {

                if (
                    strpos($delFile, $dateToday) !== false ||
                    strpos($delFile, $dateYesterday) !== false ||
                    strpos($delFile, $date2days) !== false ||
                    strpos($delFile, $date3days) !== false ||
                    strpos($delFile, $date4days) !== false ||
                    strpos($delFile, $date5days) !== false ||
                    strpos($delFile, $date6days) !== false ||
                    strpos($delFile, '/.') !== false ||
                    strpos($delFile, '/..') !== false
                ) {
                    continue;
                } else {
                    unlink($delFile);
                }
            }
        }

        return $log_file_data;
    }
}