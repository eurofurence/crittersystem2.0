<?php

namespace App\Controller\Manage;

use App\Form\Model\OperationsConfigData;
use App\Form\OperationsConfigType;
use App\Service\EventConfigStore;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/manage/operations')]
#[IsGranted('config:event')]
final class OperationsConfigController extends AbstractController
{
    public function __construct(private readonly EventConfigStore $store)
    {
    }

    #[Route('', name: 'app_manage_operations_config', methods: ['GET', 'POST'])]
    public function index(Request $request): Response
    {
        $data = $this->hydrate();

        $form = $this->createForm(OperationsConfigType::class, $data);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->store->set(EventConfigStore::KEY_BAN_NOSHOW_THRESHOLD, $data->noShowThreshold);
            $this->store->set(EventConfigStore::KEY_BAN_SCREEN_MESSAGE, $data->banScreenMessage);
            $this->store->set(EventConfigStore::KEY_MESSAGES_ENABLED, $data->messagesEnabled);
            $this->store->set(EventConfigStore::KEY_INFODESK_WELCOME, $data->infoDeskWelcome);
            $this->store->set(EventConfigStore::KEY_INFODESK_FINALIZATION, $data->infoDeskFinalization);
            $this->store->set(EventConfigStore::KEY_INFODESK_CLAIM_TIMEOUT, $data->infoDeskClaimTimeout);
            $this->store->set(EventConfigStore::KEY_MESSAGE_EDIT_WINDOW, $data->messageEditWindow);
            $this->store->set(EventConfigStore::KEY_CALL_RESPONSE_TIMEOUT, $data->callResponseTimeout);
            $this->store->set(EventConfigStore::KEY_CALL_MANAGER_LEAD, $data->callManagerLead);
            $this->store->set(EventConfigStore::KEY_SHIFT_REMINDER_LEAD, $data->shiftReminderLead);
            $this->store->set(EventConfigStore::KEY_SESSION_IDLE_MINUTES, $data->sessionIdleMinutes);
            $this->store->set(EventConfigStore::KEY_HOURS_RECOMMENDED_MAX, $data->recommendedMaxHours);
            $this->store->set(EventConfigStore::KEY_MEMBERSHIP_AUTO_FROM_LINKS, $data->autoMembershipFromLinks);
            $this->store->set(EventConfigStore::KEY_HOURS_NIGHT_START, $data->nightStartHour);
            $this->store->set(EventConfigStore::KEY_HOURS_NIGHT_END, $data->nightEndHour);
            $this->store->set(EventConfigStore::KEY_HOURS_NIGHT_MULTIPLIER, $data->nightMultiplier);
            $this->store->set(EventConfigStore::KEY_HOURS_NOSHOW_MULTIPLIER, $data->noShowMultiplier);
            $this->store->flush();

            $this->addFlash('success', 'Operational configuration saved.');

            return $this->redirectToRoute('app_manage_operations_config');
        }

        return $this->render('manage/operations_config/index.html.twig', [
            'form' => $form,
        ]);
    }

    private function hydrate(): OperationsConfigData
    {
        $store = $this->store;
        $data = new OperationsConfigData();
        $data->noShowThreshold = $store->getInt(EventConfigStore::KEY_BAN_NOSHOW_THRESHOLD, EventConfigStore::DEFAULT_BAN_NOSHOW_THRESHOLD);
        $data->banScreenMessage = $store->get(EventConfigStore::KEY_BAN_SCREEN_MESSAGE, EventConfigStore::DEFAULT_BAN_SCREEN_MESSAGE);
        $data->messagesEnabled = $store->getBool(EventConfigStore::KEY_MESSAGES_ENABLED, true);
        $data->infoDeskWelcome = $store->get(EventConfigStore::KEY_INFODESK_WELCOME, EventConfigStore::DEFAULT_INFODESK_WELCOME);
        $data->infoDeskFinalization = $store->get(EventConfigStore::KEY_INFODESK_FINALIZATION, EventConfigStore::DEFAULT_INFODESK_FINALIZATION);
        $data->infoDeskClaimTimeout = $store->getInt(EventConfigStore::KEY_INFODESK_CLAIM_TIMEOUT, EventConfigStore::DEFAULT_INFODESK_CLAIM_TIMEOUT);
        $data->messageEditWindow = $store->getInt(EventConfigStore::KEY_MESSAGE_EDIT_WINDOW, EventConfigStore::DEFAULT_MESSAGE_EDIT_WINDOW);
        $data->callResponseTimeout = $store->getInt(EventConfigStore::KEY_CALL_RESPONSE_TIMEOUT, EventConfigStore::DEFAULT_CALL_RESPONSE_TIMEOUT);
        $data->callManagerLead = $store->getInt(EventConfigStore::KEY_CALL_MANAGER_LEAD, EventConfigStore::DEFAULT_CALL_MANAGER_LEAD);
        $data->shiftReminderLead = $store->getInt(EventConfigStore::KEY_SHIFT_REMINDER_LEAD, EventConfigStore::DEFAULT_SHIFT_REMINDER_LEAD);
        $data->sessionIdleMinutes = $store->getInt(EventConfigStore::KEY_SESSION_IDLE_MINUTES, EventConfigStore::DEFAULT_SESSION_IDLE_MINUTES);
        $data->recommendedMaxHours = $store->getInt(EventConfigStore::KEY_HOURS_RECOMMENDED_MAX, EventConfigStore::DEFAULT_HOURS_RECOMMENDED_MAX);
        $data->autoMembershipFromLinks = $store->getBool(EventConfigStore::KEY_MEMBERSHIP_AUTO_FROM_LINKS, false);
        $data->nightStartHour = $store->getInt(EventConfigStore::KEY_HOURS_NIGHT_START, EventConfigStore::DEFAULT_HOURS_NIGHT_START);
        $data->nightEndHour = $store->getInt(EventConfigStore::KEY_HOURS_NIGHT_END, EventConfigStore::DEFAULT_HOURS_NIGHT_END);
        $data->nightMultiplier = $store->getFloat(EventConfigStore::KEY_HOURS_NIGHT_MULTIPLIER, EventConfigStore::DEFAULT_HOURS_NIGHT_MULTIPLIER);
        $data->noShowMultiplier = $store->getFloat(EventConfigStore::KEY_HOURS_NOSHOW_MULTIPLIER, EventConfigStore::DEFAULT_HOURS_NOSHOW_MULTIPLIER);

        return $data;
    }
}
