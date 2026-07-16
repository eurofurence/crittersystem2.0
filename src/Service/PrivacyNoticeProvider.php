<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\PrivacyNotice;

/**
 * Provides the shipped default privacy-notice body (so admins can restore it
 * after an accidental change) and renders a notice with its variables resolved.
 */
final class PrivacyNoticeProvider
{
    public function __construct(private readonly TextVariables $variables)
    {
    }

    /** The default notice body, with %variables left in place for substitution. */
    public function defaultBodyHtml(): string
    {
        return <<<'HTML'
            <h1>Volunteer Privacy Notice – %event_name</h1>
            <p>This Privacy Notice explains how %organization ("we," "us") collects, stores, and
            deletes your personal data for the %event_name volunteer system, in compliance with the
            General Data Protection Regulation (GDPR).</p>

            <h2>1. Data Controller</h2>
            <ul>
                <li>Organization: %organization</li>
                <li>Contact Email: %controller_email</li>
            </ul>

            <h2>2. Data We Collect and Why</h2>
            <p>We only process data that is strictly necessary to manage your volunteer participation.</p>
            <ul>
                <li>Email Address / Telegram Handle: processed to send you shift schedules, venue
                updates, and urgent coordination messages. Providing at least one contact method is a
                requirement to fulfill our volunteer agreement with you.</li>
                <li>Volunteer Profile Data (Name, Availability, Preferences): processed to assign you to
                appropriate roles and timeslots.</li>
            </ul>

            <h2>3. Legal Basis for Processing</h2>
            <ul>
                <li>Contractual Necessity: we process your mandatory contact info and profile data
                because it is impossible to manage your volunteer shifts without it.</li>
                <li>Consent: if you choose to provide additional contact methods, we process the
                secondary, optional method based on your explicit consent.</li>
            </ul>

            <h2>4. Data Sharing and Security</h2>
            <ul>
                <li>Your data is stored securely and is only accessible to the core volunteer
                coordination team.</li>
                <li>We never sell, rent, or share your data with third parties or external commercial
                sponsors.</li>
            </ul>

            <h2>5. Cookies</h2>
            <p>This system uses only cookies that are strictly necessary for it to work. We do not use
            any advertising, analytics, or tracking cookies, and we do not share cookie data with third
            parties, so no cookie-consent banner is required.</p>
            <ul>
                <li>Session cookie: keeps you signed in while you use the system. It is only set once you
                log in and is removed when your session ends or expires.</li>
                <li>Security (CSRF) cookie: a short-lived token that protects forms against
                cross-site request forgery. It is created when you submit a form and discarded
                immediately afterwards.</li>
            </ul>

            <h2>6. Automated Deletion Timeline</h2>
            <p>To respect your right to be forgotten, all personal data collected for this event will be
            permanently deleted and purged from our database within %deletion_days days after the
            conclusion of the convention.</p>

            <h2>7. Your Rights</h2>
            <p>Under the GDPR, you have the right to access a copy of the data we hold about you, ask us
            to correct inaccurate information, and withdraw your volunteer application and demand the
            immediate erasure of your data before the event.</p>
            <p>To exercise any of these rights, please contact us at %contact_email.</p>
            HTML;
    }

    public function applyDefault(PrivacyNotice $notice): void
    {
        $notice->setBodyHtml($this->defaultBodyHtml());
    }

    public function render(PrivacyNotice $notice): string
    {
        return $this->variables->apply($notice->getBodyHtml(), $notice);
    }
}
