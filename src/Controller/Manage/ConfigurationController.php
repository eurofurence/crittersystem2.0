<?php

namespace App\Controller\Manage;

use App\Form\ConfigurationType;
use App\Form\Model\ConfigurationData;
use App\Service\DisplaySettings;
use App\Service\EventConfigStore;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/manage/configuration')]
#[IsGranted('config:display')]
final class ConfigurationController extends AbstractController
{
    public function __construct(
        private readonly EventConfigStore $store,
        private readonly DisplaySettings $display,
    ) {
    }

    #[Route('', name: 'app_manage_configuration', methods: ['GET', 'POST'])]
    public function index(Request $request): Response
    {
        $data = $this->hydrate();

        $form = $this->createForm(ConfigurationType::class, $data);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->store->set(EventConfigStore::KEY_TIMEZONE, $data->timezone);
            $this->store->set(EventConfigStore::KEY_DATE_FORMAT, $data->dateFormat);
            $this->store->set(EventConfigStore::KEY_TIME_FORMAT, $data->timeFormat);
            $this->store->set(EventConfigStore::KEY_DATETIME_FORMAT, $data->dateTimeFormat);
            $this->store->flush();

            $this->addFlash('success', 'Configuration saved.');

            return $this->redirectToRoute('app_manage_configuration');
        }

        return $this->render('manage/configuration/index.html.twig', [
            'form' => $form,
            'now' => new \DateTimeImmutable(),
        ]);
    }

    private function hydrate(): ConfigurationData
    {
        $data = new ConfigurationData();
        $data->timezone = (string) $this->store->get(EventConfigStore::KEY_TIMEZONE, EventConfigStore::DEFAULT_TIMEZONE);
        $data->dateFormat = $this->display->dateFormat();
        $data->timeFormat = $this->display->timeFormat();
        $data->dateTimeFormat = $this->display->dateTimeFormat();

        return $data;
    }
}
