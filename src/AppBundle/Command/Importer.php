<?php

namespace AppBundle\Command;

use \DOMDocument;
use \DOMXPath;
use As3\SymfonyData\Console\Command as BaseCommand;
use As3\Modlr\Store\Store;
use Symfony\Component\Console\Input\InputArgument;
use ICanBoogie\Inflector;

class Importer extends BaseCommand
{
    /**
     * If we are in test mode, changes will not be committed to production data.
     * @var boolean
     */
    private $testMode = false;
    private $inflector;
    private $document;
    private $xpath;

    private $submissionType = 'FULL';

    const PIES_VERSION = '6.5';

    /**
     *
     */
    public function __construct($name, Store $store)
    {
        $this->store = $store;
        $this->inflector = Inflector::get();
        return parent::__construct($name);
    }

    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this->addArgument('filename', InputArgument::OPTIONAL, 'PIES XML File');
        $this->addArgument('company', InputArgument::OPTIONAL, 'Company Name');
        $this->addUsage('Pipe XML file into this command to import it.');
        parent::configure();
    }

    /**
     *
     */
    private function loadXMLFile()
    {
        $this->writeln('Loading XML data...');
        if ($filename = $this->input->getArgument('filename')) {
            $contents = file_get_contents($filename);
        }
        if (0 === ftell(STDIN)) {
            $contents = '';
            while (!feof(STDIN)) {
                $contents .= fread(STDIN, 1024);
            }
        }
        if (null === $contents) {
            throw \InvalidArgumentException('Filename or file contents must be supplied.');
        }
        $this->document = DOMDocument::loadXML($contents);
        $this->xpath = new DOMXPath($this->document);

        $version = $this->document->getElementsByTagName('PIESVersion')[0]->nodeValue;
        if (false === version_compare($version, self::PIES_VERSION, '>=')) {
            throw new \InvalidArgumentException(sprintf('The specified XML file is using PIES version %s. Version %s or higher is required.', $version, self::PIES_VERSION));
        }

        $test = $this->document->getElementsByTagName('TestFile');
        if (!empty((array) $test) && 'true' == $test[0]->nodeValue) {
            $this->testMode = true;
        }

        $this->submissionType = $this->document->getElementsByTagName('SubmissionType')[0]->nodeValue;
        $this->writeln(sprintf('Loaded XML data successfully. SubmissionType: <info>%s</info> TestMode: <info>%s</info>', $this->submissionType, $this->testMode ? 'true' : 'false'), true);
    }

    /**
     * Imports a PIES-formatted XML file
     */
    public function doCommandImport()
    {
        $this->loadXMLFile();

        // Create company/relationships
        $this->determineCompany();

        // Loop over items

            // standardize and store data, create rels

            // If `FULL` is submissionType, delete and re-insert data
            // Otherwise, do funky shit to update data.

    }

    private function determineCompany()
    {
        $keys = [
            'BrandOwnerDUNS'    => 'ParentDUNSNumber',
            'BrandOwnerGLN'     => 'ParentGLN',
            'BrandOwnerVMRSID'  => 'ParentVMRSID',
            'BrandOwnerAAIAID'  => 'ParentAAIAID',
        ];

        $this->writeln('Attempting to determine company from supplied data');
        $this->indent();
        $company = null;
        $matchedData = [];

        foreach ($keys as $field => $parent) {
            $r = $this->xpath->query(sprintf('//%s', strtolower($field)));

            $field = $this->inflector->camelize($field);

            if (!empty((array) $r) && null !== $value = $r[0]->nodeValue) {
                $matchedData[$field] = $value;
                $company = $this->store->findQuery('company', [$field => $value])->getSingleResult();
                if (null === $company) {
                    $this->writeln(sprintf('No company found for field %s with value %s.', $field, $value));
                    continue;
                }
                $this->writeln(sprintf('Found company %s for field %s with value %s.', $company->getId(), $field, $value));
                break;
            }
            $this->writeln(sprintf('Field %s was not found in supplied data.', $field));
        }
        if (null === $company) {
            $company = $this->createCompany($matchedData, $this->input->getArgument('company'));
        }

        $this->outdent();

        if (null === $parent = $company->get('parent')) {

            $this->writeln('Attempting to determine parent company from supplied data');
            $this->indent();

            foreach (array_flip($keys) as $field => $child) {
                $queryField = $field;
                $r = $this->xpath->query(sprintf('//%s', $queryField));

                $field = $this->inflector->camelize($child);

                if (!empty((array) $r) && null !== $value = $r[0]->nodeValue) {
                    $matchedData[$field] = $value;
                    $parent = $this->store->findQuery('company', [$field => $value])->getSingleResult();
                    if (null === $parent) {
                        $this->writeln(sprintf('No company found for field %s with value %s.', $field, $value));
                        continue;
                    }
                    $this->writeln(sprintf('Found company %s for field %s with value %s.', $company->getId(), $field, $value));
                    break;
                }
                $this->writeln(sprintf('Field %s was not found in supplied data.', $queryField));
            }

            if (null !== $parent) {
                $company->set('parent', $parent);
                $company->save();
            }

            $this->outdent();
        }

        if (null !== $parent) {
            $this->writeln(sprintf('Found parent <info>%s</info> for company <info>%s</info>', $parent->get('name'), $company->get('name')));
        }
        return $company;
    }

    private function createCompany(array $matchedData, $name = null)
    {
        $company = null;
        if ($name) {
            $company = $this->store->findQuery('company', ['name' => $name])->getSingleResult();
        }
        if (null === $company) {
            foreach ($matchedData as $k => $v) {
                $company = $this->store->findQuery('company', [$k => $v])->getSingleResult();
                if (null !== $company) {
                    $this->writeln(sprintf('Found company <info>%s</info> by %s.', $company->get('name'), $k));
                    break;
                }
            }
        }

        if (null === $company) {
            if (!$name) {
                if (!$this->input->isInteractive()) {
                    throw \InvalidArgumentException(sprintf('Cannot determine company from supplied data and `company` argument was not specified. Unable to continue.'));
                }
            }
            $matchedData['name'] = $name ?: $this->askInput('Company Name', $name);

            $company = $this->store->create('company');

            foreach ($matchedData as $k => $v) {
                $company->set($k, $v);
            }
            $company->save();
            $this->writeln(sprintf('Created company <info>%s</info>.', $company->get('name')));
        }

        $this->writeln(sprintf('Loaded company <info>%s</info>', $company->get('name')));

        return $company;
    }
}
