<?php
/**
 * @package     Sivaschenko\Media
 * @author      Sergii Ivashchenko <contact@sivaschenko.com>
 * @copyright   2017-2018, Sergii Ivashchenko
 * @license     MIT
 */

namespace Sivaschenko\CleanMedia\Command;

use FilesystemIterator;
use Magento\Catalog\Model\ResourceModel\Product\Gallery;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Select;
use Magento\Framework\Filesystem;
use Magento\Framework\Filesystem\Driver\File;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class CatalogMedia extends Command
{
    /**
     * Input key for removing unused images
     */
    const INPUT_KEY_REMOVE_UNUSED = 'remove_unused';

    /**
     * Inpu tkey for removing orphaned media gallery rows
     */
    const INPUT_KEY_REMOVE_ORPHANED_ROWS = 'remove_orphaned_rows';

    /**
     * Input key for listing missing files
     */
    const INPUT_KEY_LIST_MISSING = 'list_missing';

    /**
     * Input key for listing unused files
     */
    const INPUT_KEY_LIST_UNUSED = 'list_unused';

    /**
     * Input key for moving unused files
     */
    const INPUT_KEY_MOVE_UNUSED = 'move_unused';

    /**
     * Folder name for unused image storage
     */
    const DIRECTORY_MOVE_UNUSED = 'unused';

    /**
     * @var ResourceConnection
     */
    private $resource;

    /**
     * @var File
     */
    private $file;

    /**
     * @var Filesystem
     */
    private $filesystem;

    /**
     * @param ResourceConnection $resource
     * @param Filesystem $filesystem
     * @param File $file
     */
    public function __construct(ResourceConnection $resource, Filesystem $filesystem, File $file)
    {
        $this->filesystem = $filesystem;
        $this->resource = $resource;
        $this->file = $file;
        parent::__construct();
    }

    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this->setName('sivaschenko:catalog:media')
            ->setDescription('Get information about catalog product media')
            ->addOption(
                self::INPUT_KEY_REMOVE_UNUSED,
                'r',
                InputOption::VALUE_NONE,
                'Remove unused product images'
            )->addOption(
                self::INPUT_KEY_REMOVE_ORPHANED_ROWS,
                'o',
                InputOption::VALUE_NONE,
                'Remove orphaned media gallery rows'
            )->addOption(
                self::INPUT_KEY_LIST_MISSING,
                'm',
                InputOption::VALUE_NONE,
                'List missing media files'
            )->addOption(
                self::INPUT_KEY_LIST_UNUSED,
                'u',
                InputOption::VALUE_NONE,
                'List unused media files'
            )->addOption(
                self::INPUT_KEY_MOVE_UNUSED,
                'd',
                InputOption::VALUE_NONE,
                'Move unused image files to the unused folder'
            );
        parent::configure();
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $mediaGalleryPaths = $this->getMediaGalleryPaths();

        $path = BP . '/pub/media/catalog/product';
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(
                $path, FilesystemIterator::SKIP_DOTS | FilesystemIterator::FOLLOW_SYMLINKS
            )
        );

        $files = [];
        $unusedFiles = 0;
        $cachedFiles = 0;

        if ($input->getOption(self::INPUT_KEY_LIST_UNUSED)) {
            $output->writeln('Unused files:');
        }
        if ($input->getOption(self::INPUT_KEY_MOVE_UNUSED)) {
            $output->writeln('<info>Moved to the "pub/media/unused" folder</info>');
        }

        /** @var $info SplFileInfo */
        foreach ($iterator as $info) {
            $filePath = str_replace($path, '', $info->getPathname());
            if (strpos($filePath, '/cache') === 0) {
                $cachedFiles++;
                continue;
            }
            $files[] = $filePath;
            if (!in_array($filePath, $mediaGalleryPaths)) {
                $unusedFiles++;
                if ($input->getOption(self::INPUT_KEY_LIST_UNUSED)) {
                    $output->writeln($filePath);
                }
                if ($input->getOption(self::INPUT_KEY_REMOVE_UNUSED)) {
                    unlink($info->getPathname());
                }

                if ($input->getOption(self::INPUT_KEY_MOVE_UNUSED)) {
                    $directory = $this->filesystem->getDirectoryWrite(DirectoryList::MEDIA);
                    $unusedPath = str_replace(
                        $directory->getAbsolutePath(),
                        $directory->getAbsolutePath() . self::DIRECTORY_MOVE_UNUSED . "/",
                        $info->getPathname()
                    );

                    $directory->renameFile($info->getPathname(), $unusedPath);
                    $output->writeln($unusedPath);
                }
            }
        }

        if ($input->getOption(self::INPUT_KEY_REMOVE_UNUSED)) {
            $output->writeln('Unused files were removed!');
        }

        $missingFiles = array_diff($mediaGalleryPaths, $files);
        if ($input->getOption(self::INPUT_KEY_LIST_MISSING)) {
            $output->writeln('Missing media files:');
            $output->writeln(implode("\n", $missingFiles));
        }

        if ($input->getOption(self::INPUT_KEY_REMOVE_ORPHANED_ROWS)) {
            $this->resource->getConnection()->delete(
                $this->resource->getTableName(Gallery::GALLERY_TABLE),
                ['value IN (?)' => $missingFiles]
            );
        }

        $output->writeln(sprintf('Media Gallery entries: %s.', count($mediaGalleryPaths)));
        $output->writeln(sprintf('Files in directory: %s.', count($files)));
        $output->writeln(sprintf('Cached images: %s.', $cachedFiles));
        $output->writeln(sprintf('Unused files: %s.', $unusedFiles));
        $output->writeln(sprintf('Missing files: %s.', count($missingFiles)));
    }

    /**
     * @return array
     */
    private function getMediaGalleryPaths()
    {
        $connection = $this->resource->getConnection();
        $select = $connection->select()
            ->from($this->resource->getTableName(Gallery::GALLERY_TABLE))
            ->reset(Select::COLUMNS)->columns('value');

        return $connection->fetchCol($select);
    }
}
