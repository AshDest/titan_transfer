<?php

namespace App\Enums;

enum Permission: string
{
    case DashboardView = 'dashboard.view';
    case BranchesView = 'branches.view';
    case BranchesViewAll = 'branches.view-all';
    case BranchesManage = 'branches.manage';
    case UsersManage = 'users.manage';
    case RolesManage = 'roles.manage';
    case CustomersView = 'customers.view';
    case CustomersCreate = 'customers.create';
    case BeneficiariesView = 'beneficiaries.view';
    case BeneficiariesCreate = 'beneficiaries.create';
    case TransfersView = 'transfers.view';
    case TransfersCreate = 'transfers.create';
    case TransfersApprove = 'transfers.approve';
    case TransfersReject = 'transfers.reject';
    case TransfersCancel = 'transfers.cancel';
    case TransfersPay = 'transfers.pay';
    case TransfersReverse = 'transfers.reverse';
    case ExchangeRatesView = 'exchange-rates.view';
    case ExchangeRatesManage = 'exchange-rates.manage';
    case FeeRulesView = 'fee-rules.view';
    case FeeRulesManage = 'fee-rules.manage';
    case CashView = 'cash.view';
    case CashWithdraw = 'cash.withdraw';
    case CashAdjust = 'cash.adjust';
    case ReportsView = 'reports.view';
    case AuditView = 'audit.view';
    case SettingsManage = 'settings.manage';

    public function label(): string
    {
        return match ($this) {
            self::DashboardView => 'Consulter le tableau de bord',
            self::BranchesView => 'Consulter les agences',
            self::BranchesViewAll => 'Accéder à toutes les agences',
            self::BranchesManage => 'Gérer les agences',
            self::UsersManage => 'Gérer les utilisateurs',
            self::RolesManage => 'Gérer les rôles',
            self::CustomersView => 'Consulter les clients',
            self::CustomersCreate => 'Créer des clients',
            self::BeneficiariesView => 'Consulter les bénéficiaires',
            self::BeneficiariesCreate => 'Créer des bénéficiaires',
            self::TransfersView => 'Consulter les transferts',
            self::TransfersCreate => 'Créer des transferts',
            self::TransfersApprove => 'Approuver les transferts',
            self::TransfersReject => 'Rejeter les transferts',
            self::TransfersCancel => 'Annuler les transferts',
            self::TransfersPay => 'Effectuer les paiements',
            self::TransfersReverse => 'Extourner les transferts',
            self::ExchangeRatesView => 'Consulter les taux de change',
            self::ExchangeRatesManage => 'Configurer les taux de change',
            self::FeeRulesView => 'Consulter les règles de frais',
            self::FeeRulesManage => 'Configurer les règles de frais',
            self::CashView => 'Consulter la trésorerie',
            self::CashWithdraw => 'Enregistrer les sorties de caisse',
            self::CashAdjust => 'Ajuster les caisses',
            self::ReportsView => 'Consulter les rapports',
            self::AuditView => 'Consulter le journal d’audit',
            self::SettingsManage => 'Gérer les paramètres',
        };
    }
}
