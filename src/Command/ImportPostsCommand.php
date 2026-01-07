<?php

namespace App\Command;

use App\Entity\Post;
use App\Repository\PostRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Yaml\Yaml;

#[AsCommand(
    name: 'app:import-posts',
    description: 'Import Obsidian Markdown files (YAML front matter) from content/posts into the Post entity.',
)]
class ImportPostsCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly PostRepository $posts,
        private readonly string $projectDir,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument(
                'path',
                InputArgument::OPTIONAL,
                'Folder to scan (relative to project root or absolute). Defaults to content/posts',
                'content/posts'
            )
            ->addOption(
                'dry-run',
                null,
                InputOption::VALUE_NONE,
                'Parse everything but do not write to the database'
            )
            ->addOption(
                'force',
                null,
                InputOption::VALUE_NONE,
                'Persist changes (required unless --dry-run is used)'
            )
            ->addOption(
                'ext',
                null,
                InputOption::VALUE_REQUIRED,
                'File extension to scan',
                'md'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $relOrAbs = (string) $input->getArgument('path');
        $ext = ltrim((string) $input->getOption('ext'), '.');
        $dryRun = (bool) $input->getOption('dry-run');
        $force = (bool) $input->getOption('force');

        if (!$dryRun && !$force) {
            $io->error('Refusing to write without --force (or use --dry-run).');
            return Command::INVALID;
        }

        $path = $this->resolvePath($relOrAbs);

        if (!is_dir($path)) {
            $io->error(sprintf('Path not found or not a directory: %s', $path));
            return Command::FAILURE;
        }

        $finder = new Finder();
        $finder
            ->files()
            ->in($path)
            ->name(sprintf('*.%s', $ext))
            ->sortByName();

        if (!$finder->hasResults()) {
            $io->warning(sprintf('No .%s files found in %s', $ext, $path));
            return Command::SUCCESS;
        }

        $now = new \DateTimeImmutable();

        $created = 0;
        $updated = 0;
        $skipped = 0;

        foreach ($finder as $file) {
            $fullPath = $file->getRealPath() ?: $file->getPathname();
            $raw = (string) file_get_contents($fullPath);

            try {
                [$frontMatter, $body] = $this->parseObsidianFrontMatter($raw);
            } catch (\Throwable $e) {
                $skipped++;
                $io->warning(sprintf('Skipping %s: %s', $file->getRelativePathname(), $e->getMessage()));
                continue;
            }

            $title = isset($frontMatter['title']) ? trim((string) $frontMatter['title']) : '';
            $slug = isset($frontMatter['slug']) ? trim((string) $frontMatter['slug']) : '';

            // Sensible defaults: if missing, derive from filename
            if ($title === '') {
                $title = $this->humanizeFilename($file->getBasename('.' . $ext));
            }
            if ($slug === '') {
                $slug = $this->slugify($file->getBasename('.' . $ext));
            }

            $publishedAt = $this->parseDateTimeImmutable($frontMatter['publishedAt'] ?? null);

            $post = $this->posts->findOneBy(['slug' => $slug]);
            $isNew = false;

            if (!$post) {
                $post = new Post();
                $isNew = true;

                // Ensure createdAt is set even if entity constructor is currently incorrect.
                if (method_exists($post, 'setCreatedAt')) {
                    $post->setCreatedAt($now);
                }
            }

            $post->setTitle($title);
            $post->setSlug($slug);
            $post->setContent(trim($body));
            $post->setPublishedAt($publishedAt);

            if (method_exists($post, 'setUpdatedAt')) {
                $post->setUpdatedAt($now);
            }

            if ($isNew) {
                $created++;
                if (!$dryRun) {
                    $this->em->persist($post);
                }
                $io->writeln(sprintf('CREATE %-30s (%s)', $slug, $file->getRelativePathname()));
            } else {
                $updated++;
                $io->writeln(sprintf('UPDATE %-30s (%s)', $slug, $file->getRelativePathname()));
            }
        }

        if (!$dryRun) {
            $this->em->flush();
        }

        $io->success(sprintf(
            '%s. Created: %d, Updated: %d, Skipped: %d',
            $dryRun ? 'Dry-run complete' : 'Import complete',
            $created,
            $updated,
            $skipped
        ));

        return Command::SUCCESS;
    }

    private function resolvePath(string $relOrAbs): string
    {
        // If absolute, keep it. Otherwise treat as relative to project root.
        if (str_starts_with($relOrAbs, '/') || preg_match('#^[A-Za-z]:\\\\#', $relOrAbs) === 1) {
            return $relOrAbs;
        }

        return rtrim($this->projectDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . ltrim($relOrAbs, DIRECTORY_SEPARATOR);
    }

    /**
     * Parse Obsidian-style YAML front matter.
     *
     * Expected:
     * ---
     * key: value
     * ---
     * markdown body...
     *
     * Returns: [array $frontMatter, string $body]
     */
    private function parseObsidianFrontMatter(string $raw): array
    {
        $raw = ltrim($raw, "\xEF\xBB\xBF"); // strip UTF-8 BOM if present

        // Accept files with or without front matter.
        if (!preg_match('/\A---\R(.*?)\R---\R?/s', $raw, $m)) {
            return [[], $raw];
        }

        $yaml = $m[1];
        $body = (string) preg_replace('/\A---\R(.*?)\R---\R?/s', '', $raw, 1);

        $data = Yaml::parse($yaml);
        if ($data === null) {
            $data = [];
        }
        if (!is_array($data)) {
            throw new \RuntimeException('Front matter is not a YAML mapping.');
        }

        return [$data, $body];
    }

    private function parseDateTimeImmutable(mixed $value): ?\DateTimeImmutable
    {
        if ($value === null) {
            return null;
        }

        // Obsidian sometimes stores dates as YAML timestamps which may parse to \DateTimeInterface
        if ($value instanceof \DateTimeInterface) {
            return \DateTimeImmutable::createFromInterface($value);
        }

        $s = trim((string) $value);
        if ($s === '') {
            return null;
        }

        // Try a couple common formats first
        $formats = [
            'Y-m-d H:i:s',
            'Y-m-d H:i',
            'Y-m-d',
            \DateTimeInterface::ATOM,
            \DateTimeInterface::RFC3339_EXTENDED,
            \DateTimeInterface::RFC3339,
        ];

        foreach ($formats as $fmt) {
            $dt = \DateTimeImmutable::createFromFormat($fmt, $s);
            if ($dt instanceof \DateTimeImmutable) {
                return $dt;
            }
        }

        // Fallback: let PHP try
        try {
            return new \DateTimeImmutable($s);
        } catch (\Throwable) {
            throw new \RuntimeException(sprintf('Invalid publishedAt date: "%s"', $s));
        }
    }

    private function humanizeFilename(string $name): string
    {
        $name = str_replace(['_', '-'], ' ', $name);
        $name = preg_replace('/\s+/', ' ', $name) ?? $name;
        return trim(mb_convert_case($name, MB_CASE_TITLE, 'UTF-8'));
    }

    private function slugify(string $s): string
    {
        $s = mb_strtolower(trim($s), 'UTF-8');
        $s = preg_replace('/[^\p{L}\p{N}]+/u', '-', $s) ?? $s;
        $s = trim($s, '-');
        $s = preg_replace('/-+/', '-', $s) ?? $s;

        return $s !== '' ? $s : 'post';
    }
}
