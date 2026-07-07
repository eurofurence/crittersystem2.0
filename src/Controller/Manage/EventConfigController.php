<?php

namespace App\Controller\Manage;

use App\Form\EventConfigType;
use App\Form\Model\EventConfigData;
use App\Service\EventConfigStore;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/manage/event-config')]
#[IsGranted('config:event')]
final class EventConfigController extends AbstractController
{
    public function __construct(private readonly EventConfigStore $store)
    {
    }

    #[Route('', name: 'app_manage_event_config', methods: ['GET', 'POST'])]
    public function index(Request $request): Response
    {
        $data = $this->hydrate();

        $form = $this->createForm(EventConfigType::class, $data);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->store->set(EventConfigStore::KEY_NAME, $data->name);
            $this->store->set(EventConfigStore::KEY_WELCOME_MESSAGE, $data->welcomeMessage);
            $this->store->set(EventConfigStore::KEY_ACCESS_MODE, $data->accessMode);
            $this->store->set(EventConfigStore::KEY_BUILDUP_START, self::dateToString($data->buildupStart));
            $this->store->set(EventConfigStore::KEY_EVENT_START, self::dateToString($data->eventStart));
            $this->store->set(EventConfigStore::KEY_EVENT_END, self::dateToString($data->eventEnd));
            $this->store->set(EventConfigStore::KEY_TEARDOWN_END, self::dateToString($data->teardownEnd));
            $this->store->set(EventConfigStore::KEY_DEFAULT_THEME, $data->defaultTheme ?: null);
            $this->store->flush();

            $this->addFlash('success', 'Event configuration saved.');

            return $this->redirectToRoute('app_manage_event_config');
        }

        return $this->render('manage/event_config/index.html.twig', [
            'form' => $form,
        ]);
    }

    private function hydrate(): EventConfigData
    {
        $data = new EventConfigData();
        $data->name = $this->store->get(EventConfigStore::KEY_NAME);
        $data->welcomeMessage = $this->store->get(EventConfigStore::KEY_WELCOME_MESSAGE);
        $data->accessMode = (string) $this->store->get(EventConfigStore::KEY_ACCESS_MODE, 'public');
        $data->buildupStart = $this->store->getDate(EventConfigStore::KEY_BUILDUP_START);
        $data->eventStart = $this->store->getDate(EventConfigStore::KEY_EVENT_START);
        $data->eventEnd = $this->store->getDate(EventConfigStore::KEY_EVENT_END);
        $data->teardownEnd = $this->store->getDate(EventConfigStore::KEY_TEARDOWN_END);
        $data->defaultTheme = $this->store->get(EventConfigStore::KEY_DEFAULT_THEME);

        return $data;
    }

    private static function dateToString(?\DateTimeInterface $date): ?string
    {
        return $date?->format(\DATE_ATOM);
    }
}
