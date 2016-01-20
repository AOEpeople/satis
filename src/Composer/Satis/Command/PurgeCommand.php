<?php
namespace Composer\Satis\Command;

use Composer\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputArgument;
use Composer\Json\JsonFile;

class PurgeCommand extends Command
{
    /**
     * @var string
     */
    private $outputDir;

    /**
     * @var \DateTime
     */
    private $maxAge;

    /**
     * @var array
     */
    private $config;

    /**
     * @return void
     */
    protected function configure()
    {
        $this->setName('purge')
            ->setDescription('Purge packages')
            ->setDefinition(array(
                new InputArgument('file', InputArgument::OPTIONAL, 'Json file to use', './satis.json'),
                new InputArgument('output-dir', InputArgument::OPTIONAL, 'Location where to output built files', null),
                new InputArgument('max-age', InputArgument::OPTIONAL, 'Maximum age of package include files. Use format of http://www.php.net/strtotime.', '-1 week'),
            ))
            ->setHelp(file_get_contents(__DIR__ . '/../../../../purge.help.txt'));
    }

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return integer
     */
    protected function initialize(InputInterface $input, OutputInterface $output)
    {
        parent::initialize($input, $output);

        $this->initializeConfig($input, $output);
        $this->initializeOutputDir($input, $output);
        $this->initializeMaxAge($input, $output);
    }


    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return integer
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $includes = $this->getIncludeFiles();
        if (empty($includes)) {
            $output->writeln('<error>No include files found</error>');
            return 1;
        }

        $archives = scandir($this->outputDir . "/" . $this->config['archive']['directory'], 1);
        if (empty($archives)) {
            $output->writeln('<error>No archived files</error>');
            return 1;
        }

        foreach ($includes as $include => $mtime) {

            if ($this->isMaxAgeReached($mtime)) {
                $output->writeln('<info>skipping :: ' . $include . ' from ' . $mtime . '</info>');
                continue;
            }

            $output->writeln('<info>scanning :: ' . $include . ' from ' . $mtime . '</info>');

            $json = json_decode(file_get_contents($include), true);
            foreach ($json['packages'] as $packageName => $versions) {
                foreach ($versions as $packageDefinition) {
                    if (false === isset($packageDefinition['dist'])) {
                        $output->writeln(
                            "<warning>{$packageDefinition['name']} with version " .
                            "{$packageDefinition['version']} has no 'dist'</warning>"
                        );
                        continue;
                    }
                    $needed = basename($packageDefinition['dist']['url']);
                    if (($key = array_search($needed, $archives)) !== false) {
                        unset($archives[$key]);
                    }
                }
            }
        }

        foreach ($archives as $archive) {
            $absPath = $this->outputDir . '/' . $this->config['archive']['directory'] . '/' . $archive;
            if (is_file($absPath)) {
                $output->writeln("<info>" . $archive . ' :: deleted</info>');
                unlink($absPath);
            }
        }

        $output->writeln('<info>Purge :: finished</info>');

        return 0;
    }

    /**
     * Checks if the given timestamp exceed the configured max age.
     *
     * @param integer $time
     * @return boolean
     */
    private function isMaxAgeReached($time)
    {
        if ($time <= $this->maxAge->getTimestamp()) {
            return true;
        }
        return false;
    }

    /**
     * Return all include files and "include" folder as array with
     *   key: filename
     *   value: modification date of the file
     *
     * @return array
     */
    private function getIncludeFiles()
    {
        $includes = glob($this->outputDir . "/include/*.json");
        if (empty($includes)) {
            return array();
        }
        $includes = array_combine($includes, array_map("filemtime", $includes));
        asort($includes);
        return $includes;
    }

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     */
    private function initializeConfig(InputInterface $input, OutputInterface $output)
    {
        $configFile = $input->getArgument('file');
        $file = new JsonFile($configFile);
        if (!$file->exists()) {
            throw new \InvalidArgumentException('File not found: ' . $configFile);
        }
        $config = $file->read();

        if (!isset($config['archive']) || !isset($config['archive']['directory'])) {
            throw new \InvalidArgumentException('You must define "archive" parameter in your ' . $configFile);
        }
        $this->config = $config;
    }

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     */
    private function initializeOutputDir(InputInterface $input, OutputInterface $output)
    {
        if (!$outputDir = $input->getArgument('output-dir')) {
            throw new \InvalidArgumentException('The output dir must be specified as second argument');
        }
        $this->outputDir = $outputDir;
    }

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     */
    private function initializeMaxAge(InputInterface $input, OutputInterface $output)
    {
        try {
            $this->maxAge = new \DateTime(stripslashes($input->getArgument('max-age')));
        } catch (\Exception $e) {
            throw new \InvalidArgumentException(
                'invalid max-age parameter: ' . stripslashes($input->getArgument('max-age'))
            );
        }
    }
}
