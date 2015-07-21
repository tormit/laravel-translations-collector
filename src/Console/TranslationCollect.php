<?php
/**
 * @author Tormi Talv <tormi.talv@ambientia.ee> 2015
 * @since 2015-07-13 10:50
 * @version 1.0
 */

namespace Tormit\LaravelTranslationsCollector\Console;

use Illuminate\Console\Command;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Finder\SplFileInfo;
use Tormit\Helper\Transliterator;
use Tormit\Helper\Util;

class TranslationCollect extends Command
{
    const PATTERN = <<<'PAT'
/trans\(['"]{1}([\w\s_\-\.!?:,\\'"]+)['"]{1}\)/i
PAT;
    protected static $extensions = [
        'php'
    ];

    protected $verbose = 0;

    /**
     * The console command name.
     *
     * @var string
     */
    protected $name = 'translation:collect';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Collects translations from code.';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function fire()
    {
        $this->verbose = $this->input->getOption('verbose');

        if ($this->input->getOption('test')) {
            $this->scanDirectory(base_path('tests' . DIRECTORY_SEPARATOR . 'translations'));
        } else {
            $this->scanDirectory(app_path());
            $this->scanDirectory(base_path('resources' . DIRECTORY_SEPARATOR . 'views'));
        }
    }

    /**
     * @param $path
     */
    protected function scanDirectory($path)
    {
        $relativePath = str_replace(Util::autoAppendSlash(base_path(), DIRECTORY_SEPARATOR), '', $path);
        $this->comment(sprintf('Scanning recursively %s', $path));

        $directoryIterator = new \RecursiveDirectoryIterator($path);
        $iterator = new \RecursiveIteratorIterator($directoryIterator);

        $translations = [];
        $translationLocations = [];
        $duplicate = [];

        /** @var \SplFileInfo $splFile */
        foreach ($iterator as $splFile) {
            $currentFileTranslations = [];
            $relativePathname = str_replace(Util::autoAppendSlash(base_path(), DIRECTORY_SEPARATOR), '', $splFile->getPathname());

            if (!$splFile->isFile()) {
                continue;
            }

            if ($this->verbose) {
                $this->info(sprintf('Scanning file %s:', $relativePathname));
            }

            if (!in_array($splFile->getExtension(), self::$extensions)) {
                if ($this->verbose) {
                    $this->error('Invalid extension.');
                }
                continue;
            }

            $fileContent = file_get_contents($splFile->getPathname());

            preg_match_all(self::PATTERN, $fileContent, $matches, PREG_OFFSET_CAPTURE);

            if (empty($matches[1])) {
                if ($this->verbose) {
                    $this->error('No translations found.');
                }
                continue;
            }

            foreach ($matches[1] as $stringWithOffset) {
                $string = $stringWithOffset[0];
                $offset = $stringWithOffset[1];
                $string = stripslashes($string);

                if (isset($translations[$string])) {
                    $duplicate[] = $string;
                }

                $translations[$string] = $string;
                $translationLocations[$string] = $relativePathname . ':' . $offset;
                $currentFileTranslations[$string] = $string;
            }


            if ($this->verbose) {
                $this->info('Found translations:');
                $table = [];
                foreach ($currentFileTranslations as $string) {
                    $table[] = [
                        $string,
                        $translationLocations[$string]
                    ];
                }

                $this->table(
                    ['Translation string', 'Location'],
                    $table
                );
            }
        }

        $this->comment('Dumping translations.');
        $this->dumpTranslations($relativePath, $translations, $translationLocations);

        if ($this->input->getOption('append')) {
            $this->comment('Appending translations.');
            $this->appendTranslations($translations);
        }

        $this->comment('Directory scanned.');
    }

    /**
     * @param $relativePath
     * @param $translations
     * @param $translationLocations
     */
    protected function dumpTranslations($relativePath, $translations, $translationLocations)
    {
        $mainWrapper = <<<'MAIN'
<?php

////////////////////////////////////////////
// THIS FILE IS GENERATED. DO NOT CHANGE IT.
////////////////////////////////////////////

return %s;
MAIN;
        $arrayWrapper = <<<'ARRAY'
[
%s
]
ARRAY;
        $lineFormat = "  %s => %s,\n";
        $lineFormatWithComment = "  %s => %s, // %s\n";


        $dumpDir = base_path('resources' . DIRECTORY_SEPARATOR . 'lang' . DIRECTORY_SEPARATOR . 'dump');

        $dumpFile = $dumpDir . DIRECTORY_SEPARATOR . Transliterator::urlize($relativePath) . '.php';

        $this->info('Dumping into file ' . $dumpFile);

        $dataLines = '';
        foreach ($translations as $string) {
            if ($this->input->getOption('location')) {
                $dataLines .= sprintf($lineFormatWithComment, var_export($string, true), var_export($string, true), $translationLocations[$string]);
            } else {
                $dataLines .= sprintf($lineFormat, var_export($string, true), var_export($string, true));
            }
        }
        $arrayDump = sprintf($arrayWrapper, $dataLines);


        file_put_contents(
            $dumpFile,
            sprintf(
                $mainWrapper,
                $arrayDump
            )
        );
    }

    private function appendTranslations($translations)
    {
        $this->comment('New translations will be added to messages.php catalog.');

        $messagesFinder = (new Finder())->files()->name('messages.php')->in(base_path('resources' . DIRECTORY_SEPARATOR . 'lang'));

        /** @var SplFileInfo $file */
        foreach ($messagesFinder as $file) {
            $currentStrings = include $file;

            $currentStrings += $translations;

            file_put_contents($file->getPathname(), sprintf("<?php\n\n// THIS FILE WAS UPDATED BY TRANSLATION COLLECTOR\n\n return %s;\n", var_export($currentStrings, true)));
        }
    }

    /**
     * Get the console command arguments.
     *
     * @return array
     */
    protected function getArguments()
    {
        return [
        ];
    }

    /**
     * Get the console command options.
     *
     * @return array
     */
    protected function getOptions()
    {
        return [
            ['test', null, InputOption::VALUE_NONE, 'Run collector on test files.', null],
            ['location', null, InputOption::VALUE_NONE, 'Add translation location into dump as comment for each line.', null],
            ['append', null, InputOption::VALUE_NONE, 'Add missing translations for current translation files.', null],
        ];
    }

}
