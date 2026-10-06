<?php

namespace Tests\Unit\Support;

use App\Support\Env\EnvDoctor;
use Tests\TestCase;

class EnvDoctorTest extends TestCase
{
    public function test_the_last_duplicate_wins_and_cleaning_keeps_that_value(): void
    {
        $env = "APP_NAME=First\nLIVE_TRADING_ENABLED=true\nAPP_NAME=Second\nLIVE_TRADING_ENABLED=false\n";

        $this->assertSame('Second', EnvDoctor::effective($env)['APP_NAME']);
        $cleaned = EnvDoctor::clean($env);

        $this->assertSame("APP_NAME=Second\nLIVE_TRADING_ENABLED=false\n", $cleaned['content']);
        $this->assertTrue(EnvDoctor::sameEffective($env, $cleaned['content']));
        $this->assertSame(['count' => 2, 'differs' => true, 'effective' => 'false'], EnvDoctor::duplicates($env)['LIVE_TRADING_ENABLED']);
    }

    public function test_bom_crlf_and_trailing_spaces_are_removed_without_changing_any_value(): void
    {
        $env = "\xEF\xBB\xBFDB_DATABASE=zcadmin_autofinx\r\nDB_HOST=127.0.0.1   \r\nQUOTED=\"a b \"  \r\n";
        $cleaned = EnvDoctor::clean($env);

        $this->assertSame("DB_DATABASE=zcadmin_autofinx\nDB_HOST=127.0.0.1\nQUOTED=\"a b \"\n", $cleaned['content']);
        $this->assertTrue(EnvDoctor::sameEffective($env, $cleaned['content']));
        $this->assertSame('zcadmin_autofinx', EnvDoctor::effective($cleaned['content'])['DB_DATABASE']);   // not "\xEF\xBB\xBFDB_DATABASE"
    }

    public function test_a_multi_line_quoted_value_survives_and_a_duplicate_of_it_is_removed_whole(): void
    {
        $env = "KEY=\"line1\nline2\"\nOTHER=1\nKEY=\"final\nvalue\"\n";
        $cleaned = EnvDoctor::clean($env);

        $this->assertSame("OTHER=1\nKEY=\"final\nvalue\"\n", $cleaned['content']);
        $this->assertTrue(EnvDoctor::sameEffective($env, $cleaned['content']));
    }

    public function test_comments_and_blank_lines_are_kept_and_a_clean_file_is_untouched(): void
    {
        $env = "# section\nA=1\n\nB=2\n";
        $cleaned = EnvDoctor::clean($env);

        $this->assertFalse($cleaned['changed']);
        $this->assertSame($env, $cleaned['content']);
    }

    public function test_a_syntax_error_is_reported_by_the_same_parser_laravel_uses(): void
    {
        $this->expectException(\Dotenv\Exception\InvalidFileException::class);
        EnvDoctor::effective("GOOD=1\nBAD KEY=oops with spaces\n");
    }

    public function test_secrets_are_never_printed(): void
    {
        $this->assertSame('(set, 12 chars, hidden)', EnvDoctor::mask('BINANCE_API_SECRET', 'abcdefghijkl'));
        $this->assertSame('(empty)', EnvDoctor::mask('APP_KEY', ''));
        $this->assertSame('Asia/Colombo', EnvDoctor::mask('APP_TIMEZONE', 'Asia/Colombo'));
        $findings = json_encode(EnvDoctor::findings(
            ['A_KEY' => 'topsecretvalue1', 'B_KEY' => 'topsecretvalue2'], ['A_KEY', 'B_KEY'], ['A_KEY' => 'B_KEY']));
        $this->assertStringNotContainsString('topsecretvalue', (string) $findings);
        $this->assertStringContainsString('overrides', (string) $findings);
    }

    public function test_findings_flag_real_risks_and_typos_but_not_framework_defaults(): void
    {
        $messages = fn (array $effective, array $known = [], array $aliases = []) => implode("\n", array_map(
            fn ($f) => $f['level'].' '.$f['message'], EnvDoctor::findings($effective, $known, $aliases)));

        $risky = $messages(['APP_ENV' => 'production', 'APP_DEBUG' => 'true', 'APP_KEY' => '', 'CACHE_STORE' => 'array', 'QUEUE_CONNECTION' => 'sync']);
        $this->assertStringContainsString('FAIL APP_DEBUG is on in production', $risky);
        $this->assertStringContainsString('FAIL APP_KEY is empty', $risky);
        $this->assertStringContainsString('"array"', $risky);
        $this->assertStringContainsString('QUEUE_CONNECTION=sync', $risky);

        $typo = $messages(['APP_ENV' => 'production', 'APP_KEY' => 'x', 'AUTOFINX_SCHEDULER_ENABLE' => 'true'], ['AUTOFINX_SCHEDULER_ENABLED']);
        $this->assertStringContainsString('did you mean AUTOFINX_SCHEDULER_ENABLED?', $typo);

        $this->assertStringNotContainsString('placeholder', $messages(['APP_ENV' => 'production', 'APP_KEY' => 'x', 'REDIS_PASSWORD' => 'null']));
        $this->assertStringContainsString('placeholder', $messages(['APP_ENV' => 'production', 'APP_KEY' => 'x', 'API_SECRET' => 'your_secret_here']));

        // An alias that is set to the same value, or only one side set, is not a conflict.
        $this->assertStringNotContainsString('overrides', $messages(['APP_ENV' => 'production', 'APP_KEY' => 'x', 'A' => '1', 'B' => '1'], [], ['A' => 'B']));
        $this->assertStringNotContainsString('overrides', $messages(['APP_ENV' => 'production', 'APP_KEY' => 'x', 'A' => '1', 'B' => ''], [], ['A' => 'B']));
    }
}
