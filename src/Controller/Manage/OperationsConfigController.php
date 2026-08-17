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
use Symfony\Component\Translation\TranslatableMessage;

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
            $this->store->set(EventConfigStore::KEY_SECURITY_CHECKIN_WINDOW, $data->securityCheckInWindow);
            $this->store->set(EventConfigStore::KEY_CHECKIN_MESSAGE_EN, $data->checkInMessageEn);
            $this->store->set(EventConfigStore::KEY_CHECKIN_MESSAGE_DE, $data->checkInMessageDe);
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
            $this->store->set(EventConfigStore::KEY_BOARD_PRE_START_WARN_MIN, $data->boardPreStartWarnMin);
            $this->store->set(EventConfigStore::KEY_BOARD_MAX_CONTINUOUS_MIN, $data->boardMaxContinuousMin);
            $this->store->set(EventConfigStore::KEY_BOARD_MAX_SEQUENTIAL_MIN, $data->boardMaxSequentialMin);
            $this->store->set(EventConfigStore::KEY_BOARD_UNATTENDED_MIN, $data->boardUnattendedMin);
            $this->store->set(EventConfigStore::KEY_BOARD_OVERWORK_WARN_FRACTION, $data->boardOverworkWarnFraction);
            $this->store->set(EventConfigStore::KEY_BOARD_CARD_BANDS, $data->boardCardBands);
            $this->store->set(EventConfigStore::KEY_BOARD_WORKLOAD_BANDS, $data->boardWorkloadBands);
            $this->store->set(EventConfigStore::KEY_BOARD_COMING_WINDOW_MIN, $data->boardComingWindowMin);
            $this->store->set(EventConfigStore::KEY_BOARD_OFF_DUTY_WINDOW_MIN, $data->boardOffDutyWindowMin);
            $this->store->set(EventConfigStore::KEY_BOARD_FORECAST_HORIZON_HOURS, $data->boardForecastHorizonHours);
            $this->store->set(EventConfigStore::KEY_BOARD_FORECAST_STEP_HOURS, $data->boardForecastStepHours);
            $this->store->set(EventConfigStore::KEY_BOARD_ACTIVE_STAFF_TOP_N, $data->boardActiveStaffTopN);
            $this->store->set(EventConfigStore::KEY_BOARD_PAGE_SIZE_STAFF, $data->boardPageSizeStaff);
            $this->store->set(EventConfigStore::KEY_BOARD_PAGE_SIZE_SHIFTS, $data->boardPageSizeShifts);
            $this->store->flush();

            $this->addFlash('success', new TranslatableMessage('manage.operations_config.flash.saved'));

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
        $data->securityCheckInWindow = $store->getInt(EventConfigStore::KEY_SECURITY_CHECKIN_WINDOW, EventConfigStore::DEFAULT_SECURITY_CHECKIN_WINDOW);
        $data->checkInMessageEn = $store->getString(EventConfigStore::KEY_CHECKIN_MESSAGE_EN, EventConfigStore::DEFAULT_CHECKIN_MESSAGE_EN);
        $data->checkInMessageDe = $store->getString(EventConfigStore::KEY_CHECKIN_MESSAGE_DE, EventConfigStore::DEFAULT_CHECKIN_MESSAGE_DE);
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
        $data->boardPreStartWarnMin = $store->getInt(EventConfigStore::KEY_BOARD_PRE_START_WARN_MIN, EventConfigStore::DEFAULT_BOARD_PRE_START_WARN_MIN);
        $data->boardMaxContinuousMin = $store->getInt(EventConfigStore::KEY_BOARD_MAX_CONTINUOUS_MIN, EventConfigStore::DEFAULT_BOARD_MAX_CONTINUOUS_MIN);
        $data->boardMaxSequentialMin = $store->getInt(EventConfigStore::KEY_BOARD_MAX_SEQUENTIAL_MIN, EventConfigStore::DEFAULT_BOARD_MAX_SEQUENTIAL_MIN);
        $data->boardUnattendedMin = $store->getInt(EventConfigStore::KEY_BOARD_UNATTENDED_MIN, EventConfigStore::DEFAULT_BOARD_UNATTENDED_MIN);
        $data->boardOverworkWarnFraction = $store->getFloat(EventConfigStore::KEY_BOARD_OVERWORK_WARN_FRACTION, EventConfigStore::DEFAULT_BOARD_OVERWORK_WARN_FRACTION);
        $data->boardCardBands = $store->getString(EventConfigStore::KEY_BOARD_CARD_BANDS, EventConfigStore::DEFAULT_BOARD_CARD_BANDS);
        $data->boardWorkloadBands = $store->getString(EventConfigStore::KEY_BOARD_WORKLOAD_BANDS, EventConfigStore::DEFAULT_BOARD_WORKLOAD_BANDS);
        $data->boardComingWindowMin = $store->getInt(EventConfigStore::KEY_BOARD_COMING_WINDOW_MIN, EventConfigStore::DEFAULT_BOARD_COMING_WINDOW_MIN);
        $data->boardOffDutyWindowMin = $store->getInt(EventConfigStore::KEY_BOARD_OFF_DUTY_WINDOW_MIN, EventConfigStore::DEFAULT_BOARD_OFF_DUTY_WINDOW_MIN);
        $data->boardForecastHorizonHours = $store->getInt(EventConfigStore::KEY_BOARD_FORECAST_HORIZON_HOURS, EventConfigStore::DEFAULT_BOARD_FORECAST_HORIZON_HOURS);
        $data->boardForecastStepHours = $store->getInt(EventConfigStore::KEY_BOARD_FORECAST_STEP_HOURS, EventConfigStore::DEFAULT_BOARD_FORECAST_STEP_HOURS);
        $data->boardActiveStaffTopN = $store->getInt(EventConfigStore::KEY_BOARD_ACTIVE_STAFF_TOP_N, EventConfigStore::DEFAULT_BOARD_ACTIVE_STAFF_TOP_N);
        $data->boardPageSizeStaff = $store->getInt(EventConfigStore::KEY_BOARD_PAGE_SIZE_STAFF, EventConfigStore::DEFAULT_BOARD_PAGE_SIZE_STAFF);
        $data->boardPageSizeShifts = $store->getInt(EventConfigStore::KEY_BOARD_PAGE_SIZE_SHIFTS, EventConfigStore::DEFAULT_BOARD_PAGE_SIZE_SHIFTS);

        return $data;
    }
}
