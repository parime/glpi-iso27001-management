<?php

declare(strict_types=1);

namespace GlpiPlugin\Grcmanager\Services\Policy;

/**
 * Pure validation/decision logic behind the security policy library (issue #28, ISO/IEC
 * 27001:2022 A.5.1/A.5.1.1/A.5.1.2), see \GlpiPlugin\Grcmanager\Policy. Kept GLPI-independent (no $DB, no
 * CommonDBTM, no __()) so every status/approval-date combination can be unit tested directly
 * (tests/Unit/Services/Policy/PolicyLifecycleTest.php), the same split already used for the C/I/D
 * asset classification register (ClassificationLevels, issue #26) and the risk <-> CMDB item link
 * (RiskItemLinkNormalizer, issue #25): pure decision logic here, thin $DB/HTML wrapper on the
 * CommonDBTM class itself (inc/policy.class.php).
 *
 * Three states only (draft/approved/archived), no separate "under review" state: a policy under
 * revision by the RSSI is still, operationally, its last approved version until the new one is
 * itself approved (same "one current version at a time" model the issue describes), and
 * `next_review_date` already carries the "this needs attention soon" signal independently of
 * `status` (see PolicyReviewReminderWindow).
 */
final class PolicyLifecycle
{
    public const STATUS_DRAFT    = 'draft';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_ARCHIVED = 'archived';

    /**
     * @var array<int, string>
     */
    public const ALLOWED_STATUSES = [self::STATUS_DRAFT, self::STATUS_APPROVED, self::STATUS_ARCHIVED];

    public static function isValid(?string $status): bool
    {
        return $status !== null && in_array($status, self::ALLOWED_STATUSES, true);
    }

    /**
     * Never trusts a raw request value as-is (same defensive posture as
     * ClassificationLevels::sanitize()): an invalid/missing value always falls back to the
     * "draft" default, never stored as garbage.
     */
    public static function sanitize(?string $status): string
    {
        return self::isValid($status) ? $status : self::STATUS_DRAFT;
    }

    /**
     * ISO 27001 A.5.1.1 requires a documented approval: a policy cannot be marked "approved"
     * without a recorded approval date, same server-side "field X required for state Y" pattern
     * already enforced by PluginGrcmanagerControl::validateAndMarkReviewed() (justification
     * required unless fully applicable).
     */
    public static function requiresApprovalDate(string $status): bool
    {
        return $status === self::STATUS_APPROVED;
    }

    public static function isApprovalDateMissing(string $status, ?string $approvalDate): bool
    {
        return self::requiresApprovalDate($status) && trim((string) $approvalDate) === '';
    }
}
