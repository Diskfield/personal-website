<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class PagesController extends AbstractController
{
    #[Route('/about', name: 'about', methods: ['GET'])]
    public function about(): Response
    {
        return $this->render('pages/about.html.twig');
    }

    #[Route('/tooling', name: 'tooling', methods: ['GET'])]
    public function uses(): Response
    {
        return $this->render('pages/tooling.html.twig');
    }

    #[Route('/music', name: 'music', methods: ['GET'])]
    public function music(): Response
    {
        return $this->render('pages/music.html.twig');
    }
}
