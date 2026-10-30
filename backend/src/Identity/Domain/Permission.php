<?php

namespace App\Identity\Domain;

enum Permission: string
{
    case ORGANIZATION_READ = 'org.read';
    case ORGANIZATION_MANAGE_MEMBERS = 'org.manage_members';
    case EVENT_READ = 'event.read';
    case EVENT_MANAGE = 'event.manage';
    case CATALOG_MANAGE = 'catalog.manage';
    case COURSE_MANAGE = 'course.manage';
    case EXPENSE_WRITE = 'expense.write';
    case SALE_WRITE = 'sale.write';
    case PAYMENT_REVIEW = 'payment.review';
    case TICKET_READ = 'ticket.read';
    case TICKET_REDEEM = 'ticket.redeem';
    case REPORT_READ = 'report.read';
    case REPORT_EXPORT = 'report.export';
    case BALANCE_READ = 'balance.read';
    case EVENT_CLOSE = 'event.close';
    case AUDIT_READ = 'audit.read';
}
