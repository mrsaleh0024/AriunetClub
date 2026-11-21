<?php

namespace LClub\Tests;

use LClub\Modules\Rules\ReversalMath;
use PHPUnit\Framework\TestCase;

class ReversalMathTest extends TestCase {
        public function test_partial_refund_reverses_exactly_proportionally(): void {
                // 200 تومان از مبنا ۱۰۰۰ با کش‌بک ۵۰ → دقیقاً ۱۰.
                $this->assertSame( 10.0, ReversalMath::proportional( 50.0, 200.0, 1000.0, 0.0 ) );
                // نسبت غیررند: ۳۳۰ از ۱۰۰۰ با کش‌بک ۶۰ → ۱۹٫۸ → گرد به ۲۰ با صفر اعشار.
                $this->assertSame( 20.0, ReversalMath::proportional( 60.0, 330.0, 1000.0, 0.0 ) );
        }

        public function test_full_refund_clears_all_remaining_earned(): void {
                $this->assertSame( 50.0, ReversalMath::proportional( 50.0, 1000.0, 1000.0, 0.0 ) );
                $this->assertSame( 50.0, ReversalMath::proportional( 50.0, 2000.0, 1000.0, 0.0 ) );
        }

        public function test_cumulative_refunds_never_exceed_earned(): void {
                $first = ReversalMath::proportional( 50.0, 200.0, 1000.0, 0.0 );
                $this->assertSame( 10.0, $first );

                $second = ReversalMath::proportional( 50.0, 200.0, 1000.0, $first );
                $this->assertSame( 10.0, $second );

                // مرجوعی بزرگ‌تر از باقی‌ماندهٔ مجاز فقط تا سقفِ کسب‌شده برگشت می‌خورد.
                $this->assertSame( 30.0, ReversalMath::proportional( 50.0, 1000.0, 1000.0, 20.0 ) );
                $this->assertSame( 0.0, ReversalMath::proportional( 50.0, 500.0, 1000.0, 50.0 ) );
        }

        public function test_invalid_inputs_produce_zero(): void {
                $this->assertSame( 0.0, ReversalMath::proportional( 0.0, 100.0, 1000.0, 0.0 ) );
                $this->assertSame( 0.0, ReversalMath::proportional( 50.0, 0.0, 1000.0, 0.0 ) );
                $this->assertSame( 0.0, ReversalMath::proportional( 50.0, 100.0, 0.0, 0.0 ) );
                $this->assertSame( 0.0, ReversalMath::proportional( -5.0, 100.0, 1000.0, 0.0 ) );
        }

        public function test_full_reversal_covers_remaining_earned(): void {
                $this->assertSame( 50.0, ReversalMath::full( 50.0, 0.0 ) );
                // با گردکردن صفر اعشار (تومان): ۳۷٫۵ → ۳۸.
                $this->assertSame( 38.0, ReversalMath::full( 50.0, 12.5 ) );
                $this->assertSame( 0.0, ReversalMath::full( 50.0, 60.0 ) );
                $this->assertSame( 0.0, ReversalMath::full( 0.0, 0.0 ) );
                $this->assertSame( 0.0, ReversalMath::full( -5.0, 0.0 ) );
        }
}
