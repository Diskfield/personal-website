<?php

namespace App\Controller;

use App\Repository\PostRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class BlogController extends AbstractController
{
    #[Route('/blog', name: 'blog_index', methods: ['GET'])]
    public function index(PostRepository $posts): Response
    {
        $items = $posts->findPublishedLatest();

        return $this->render('blog/index.html.twig', [
            'posts' => $items,
        ]);
    }

    #[Route('/blog/{slug}', name: 'blog_show', methods: ['GET'])]
    public function show(string $slug, PostRepository $posts): Response
    {
        $post = $posts->findOnePublishedBySlug($slug);

        if (!$post) {
            throw $this->createNotFoundException();
        }

        return $this->render('blog/show.html.twig', [
            'post' => $post,
        ]);
    }
}
