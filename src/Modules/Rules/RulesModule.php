<?php
/**
 * @package AriunetClub
 */

namespace AriunetClub\Modules\Rules;

use AriunetClub\Core\Module;
use AriunetClub\Core\Settings;
use AriunetClub\Modules\Credit\CreditPolicy;
use AriunetClub\Modules\Ledger\LedgerRepository;
use AriunetClub\Modules\Ledger\LedgerService;
use AriunetClub\Modules\Rules\Conditions\Category;
use AriunetClub\Modules\Rules\Conditions\FirstOrder;
use AriunetClub\Modules\Rules\Conditions\MinAmount;
use AriunetClub\Modules\Rules\Conditions\PaymentMethod;
use AriunetClub\Modules\Rules\Conditions\Product;
use AriunetClub\Modules\Rules\Conditions\UserRole;
use AriunetClub\Modules\Rules\Rewards\FixedReward;
use AriunetClub\Modules\Rules\Rewards\PercentageReward;

/**
 * Phase 2 module: evaluates "event → conditions → reward" rules on the ledger.
 */
class RulesModule implements Module {
        public function id(): string {
                        return 'rules';
        }

                /**
                 * @return string[]
                 */
        public function dependencies(): array {
                        return array( 'core', 'events', 'ledger', 'credit' );
        }

        public function boot(): void {
                        global $wpdb;

                        $repository = new RuleRepository( $wpdb );

                        $ledger_service = isset( $GLOBALS['ariunetclub_ledger_service'] ) && $GLOBALS['ariunetclub_ledger_service'] instanceof LedgerService
                                        ? $GLOBALS['ariunetclub_ledger_service']
                                        : new LedgerService( new LedgerRepository( $wpdb ) );
                        $ledger_gateway = new LedgerRepository( $wpdb );

                        $settings = new Settings();
                        $policy   = isset( $GLOBALS['ariunetclub_credit_policy'] ) && $GLOBALS['ariunetclub_credit_policy'] instanceof CreditPolicy
                                        ? $GLOBALS['ariunetclub_credit_policy']
                                        : null;
                        $engine   = new RuleEngine(
                                array(
                                        'min_amount'     => new MinAmount(),
                                        'category'       => new Category(),
                                        'product'        => new Product(),
                                        'first_order'    => new FirstOrder(),
                                        'user_role'      => new UserRole(),
                                        'payment_method' => new PaymentMethod(),
                                ),
                                array(
                                        'percent' => new PercentageReward(),
                                        'fixed'   => new FixedReward(),
                                )
                        );

                        $service = new RewardService(
                                $repository,
                                $engine,
                                new LimitChecker( $ledger_gateway ),
                                $ledger_service,
                                $ledger_gateway,
                                new WcContextFactory( $settings ),
                                new WcOrderHistory(),
                                new WcOrderLookup( $settings ),
                                $policy
                        );

                        add_action( 'ariunetclub_event_processed', array( $service, 'handle_event' ) );
                        ( new RulesAdminPage( $repository, $settings ) )->register();

                        $GLOBALS['ariunetclub_rule_service'] = $service;
        }
}
