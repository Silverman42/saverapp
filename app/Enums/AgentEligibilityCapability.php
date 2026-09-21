<?php

namespace App\Enums;

enum AgentEligibilityCapability: string
{
    case ReadAssignedCustomers = 'read_assigned_customers';
    case PerformAssignedCustomerWork = 'perform_assigned_customer_work';
    case ReceiveAssignment = 'receive_assignment';
}
