<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use App\Support\Tenant;
use Database\Seeders\DatabaseSeeder;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AccessibilityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        Tenant::set(Organization::value('id'));
    }

    /** @return array<string, array{string}> */
    public static function pages(): array
    {
        $urls = ['dashboard', 'account', 'accounting', 'accounting/accounts', 'accounting/accounts/create', 'accounting/balance-sheet',
            'accounting/cash-book', 'accounting/cash-flow', 'accounting/changes-in-equity', 'accounting/closing', 'accounting/general-journal',
            'accounting/highlights', 'accounting/income-statement', 'accounting/journal', 'accounting/journal/create', 'accounting/ledger',
            'accounting/loan-aging', 'accounting/reports', 'accounting/schedules', 'accounting/trial-balance', 'cash-disbursements',
            'cash-receipts', 'collections', 'collections/new', 'loan-products', 'loan-products/create', 'loans', 'loans/create', 'members',
            'members/create', 'reports', 'savings', 'share-capital', 'transactions', 'settings', 'users', 'users/create'];

        return array_combine($urls, array_map(fn ($u) => [$u], $urls));
    }

    private function page(string $url, string $role = 'super_admin'): DOMXPath
    {
        $html = $this->actingAs(User::where('role', $role)->firstOrFail())->get('/'.$url)->assertOk()->getContent();
        $dom = new DOMDocument;
        @$dom->loadHTML($html);

        return new DOMXPath($dom);
    }

    #[DataProvider('pages')]
    public function test_page_has_one_h1(string $url): void
    {
        $this->assertSame(1, $this->page($url)->query('//h1')->length);
    }

    #[DataProvider('pages')]
    public function test_page_has_skip_link_and_one_main(string $url): void
    {
        $x = $this->page($url);
        $this->assertGreaterThan(0, $x->query('//a[@href="#main"]')->length);
        $this->assertSame(1, $x->query('//main[@id="main"]')->length);
    }

    #[DataProvider('pages')]
    public function test_every_field_has_a_label(string $url): void
    {
        $x = $this->page($url);
        $missing = [];
        $fields = $x->query('//input[not(@type="hidden" or @type="checkbox" or @type="radio" or @type="submit" or @type="button")]|//select|//textarea');
        foreach ($fields as $f) {
            $id = $f->getAttribute('id');
            $named = $f->getAttribute('aria-label') !== ''
                || ($id !== '' && $x->query('//label[@for="'.$id.'"]')->length > 0)
                || $x->query('ancestor::label', $f)->length > 0;
            if (! $named) {
                $missing[] = $f->getAttribute('name') ?: $f->getNodePath();
            }
        }
        $this->assertSame([], $missing, "Unlabeled fields on /$url");
    }

    public function test_cashier_savings_page_has_landmarks(): void
    {
        $x = $this->page('savings', 'cashier');
        $this->assertGreaterThan(0, $x->query('//a[@href="#main"]')->length);
        $this->assertSame(1, $x->query('//main[@id="main"]')->length);
    }

    public function test_starter_route_is_gone(): void
    {
        $this->actingAs(User::where('role', 'super_admin')->firstOrFail())->get('/starter')->assertNotFound();
    }

    public function test_failed_member_form_links_error_to_field(): void
    {
        $r = $this->actingAs(User::where('role', 'super_admin')->firstOrFail())->from('/members/create')->post('/members', []);
        $html = $this->followRedirects($r)->getContent();
        $dom = new DOMDocument;
        @$dom->loadHTML($html);
        $x = new DOMXPath($dom);
        $bad = $x->query('//*[@aria-invalid="true"]');
        $this->assertGreaterThan(0, $bad->length);
        foreach ($bad as $field) {
            $this->assertGreaterThan(0, $x->query('//*[@id="'.$field->getAttribute('aria-describedby').'"]')->length);
        }
    }
}
