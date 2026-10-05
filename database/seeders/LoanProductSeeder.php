<?php

namespace Database\Seeders;

use App\Models\LoanProduct;
use Illuminate\Database\Seeder;

class LoanProductSeeder extends Seeder
{
    public function run(): void
    {
        // Sample products. Rename, edit or delete them from Loan Product Management.
        $products = [
            [
                'code' => 'REG', 'name' => 'Regular Loan', 'category' => 'Regular Loan', 'description' => 'General purpose loan',
                'interest_rate' => 12, 'min_amount' => 5000, 'max_amount' => 200000, 'min_term' => 3, 'max_term' => 24,
                'grace_days' => 3, 'penalty_type' => 'percentage', 'penalty_value' => 2, 'penalty_basis' => 'per_month',
                'required_documents' => ['valid_id', 'proof_of_income', 'application_form'], 'approval_level' => 'manager',
                'deductions' => [
                    ['Service Fee', 'percentage', 2, 'upon_release'],
                    ['Processing Fee', 'fixed', 100, 'upon_release'],
                ],
            ],
            [
                'code' => 'EMG', 'name' => 'Emergency Loan', 'category' => 'Emergency Loan', 'description' => 'Quick, short-term loan for urgent needs',
                'interest_rate' => 10, 'min_amount' => 1000, 'max_amount' => 30000, 'min_term' => 1, 'max_term' => 6,
                'approval_level' => 'officer', 'required_documents' => ['valid_id'], 'deductions' => [],
            ],
            [
                'code' => 'BUS', 'name' => 'Business Loan', 'category' => 'Business Loan', 'description' => 'Capital for small business owners',
                'interest_rate' => 14, 'min_amount' => 20000, 'max_amount' => 500000, 'min_term' => 6, 'max_term' => 36,
                'interest_type' => 'diminishing', 'guarantor_required' => true, 'guarantors_required' => 1,
                'min_savings' => 1000, 'approval_level' => 'committee',
                'required_documents' => ['valid_id', 'business_permit', 'address_proof', 'application_form'],
                'deductions' => [
                    ['Service Fee', 'percentage', 1.5, 'upon_release'],
                    ['Insurance Fee', 'fixed', 250, 'add_to_payable'],
                ],
            ],
            [
                'code' => 'EDU', 'name' => 'Educational Loan', 'category' => 'Educational Loan', 'description' => 'Tuition and school expenses',
                'interest_rate' => 8, 'min_amount' => 5000, 'max_amount' => 100000, 'min_term' => 3, 'max_term' => 12,
                'required_documents' => ['valid_id', 'application_form'], 'deductions' => [],
            ],
        ];

        foreach ($products as $row) {
            $deductions = $row['deductions'];
            unset($row['deductions']);

            $product = LoanProduct::updateOrCreate(['code' => $row['code']], $row + ['is_active' => true]);

            if ($product->deductions()->count() === 0) {
                foreach ($deductions as [$name, $type, $value, $timing]) {
                    $product->deductions()->create(['name' => $name, 'type' => $type, 'value' => $value, 'timing' => $timing]);
                }
            }
        }
    }
}
