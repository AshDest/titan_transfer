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
    case CashAdjust = 'cash.adjust';
    case ReportsView = 'reports.view';
    case AuditView = 'audit.view';
    case SettingsManage = 'settings.manage';
}
