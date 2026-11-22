<?php

namespace modules\wauw\controllers;

use Craft;
use craft\commerce\elements\conditions\orders\ShippingAddressZoneConditionRule;
use craft\commerce\elements\conditions\orders\ShippingRuleOrderCondition;
use craft\commerce\models\ShippingRule;
use craft\commerce\models\ShippingRuleCategory;
use craft\commerce\Plugin as CommercePlugin;
use craft\commerce\records\ShippingRuleCategory as ShippingRuleCategoryRecord;
use craft\helpers\MoneyHelper;
use craft\web\Controller;
use craft\web\Response;
use yii\web\NotFoundHttpException;

/**
 * A flat zone x category price grid, one per shipping method. Commerce already models
 * this (a rule per method+zone, a rate per category on that rule) but spreads it across
 * Method -> Rule -> Costs tab per zone, which doesn't let store owners see or adjust all
 * prices for a method at once. Zones, categories and methods stay managed in Commerce's
 * own CP; this only edits the rule/rate records underneath them. Each method gets its own
 * page (rather than tabs on one page) so visiting one method's grid never has to load
 * every other method's rates too; the method picker is a plain nav rendered inside our own
 * template, not grafted onto Commerce's Store Management nav.
 */
class ShippingController extends Controller {
    public function beforeAction($action): bool {
        $this->requirePermission('commerce-manageShipping');

        return parent::beforeAction($action);
    }

    /**
     * There's nothing to show at the bare "Shipping management" entry point itself, so it
     * just lands on the first method, the same way Commerce's own Store Management index
     * redirects to the first section the user has access to.
     */
    public function actionIndex(): Response {
        $store = CommercePlugin::getInstance()->getStores()->getPrimaryStore();
        $firstMethod = CommercePlugin::getInstance()->getShippingMethods()->getAllShippingMethods($store->id)->first();

        if (!$firstMethod) {
            throw new NotFoundHttpException('No shipping methods configured yet.');
        }

        return $this->redirect($store->getStoreSettingsUrl("shipping-overview/{$firstMethod->handle}"));
    }

    public function actionMethod(string $methodHandle): Response {
        $store = CommercePlugin::getInstance()->getStores()->getPrimaryStore();
        $methods = CommercePlugin::getInstance()->getShippingMethods()->getAllShippingMethods($store->id);
        $method = CommercePlugin::getInstance()->getShippingMethods()->getShippingMethodByHandle($methodHandle, $store->id);

        if (!$method) {
            throw new NotFoundHttpException('Shipping method not found.');
        }

        $zones = CommercePlugin::getInstance()->getShippingZones()->getAllShippingZones($store->id);
        $categories = CommercePlugin::getInstance()->getShippingCategories()->getAllShippingCategories($store->id);

        $rates = [];
        $ruleUrls = [];
        foreach ($zones as $zone) {
            $rule = $this->findRuleForZone($method->id, $zone->id);
            // No rule yet for this zone (nothing's been saved here) -> send them to the
            // method's rule list instead, where they can see/create it, rather than a dead link.
            $ruleUrls[$zone->id] = $rule
                ? $store->getStoreSettingsUrl("shippingmethods/{$method->id}/shippingrules/{$rule->id}")
                : $method->getCpEditUrl();

            foreach ($categories as $category) {
                $rates[$zone->id][$category->id] = $rule?->getShippingRuleCategories()[$category->id]->perItemRate ?? null;
            }
        }

        $currency = $store->getCurrency();

        return $this->renderTemplate('wauw/shipping/_method.twig', [
            'store' => $store,
            'methods' => $methods,
            'method' => $method,
            'zones' => $zones,
            'categories' => $categories,
            'rates' => $rates,
            'ruleUrls' => $ruleUrls,
            'currency' => $currency,
            'decimals' => CommercePlugin::getInstance()->getCurrencies()->getSubunitFor($currency),
        ]);
    }

    public function actionSave(): ?Response {
        $this->requirePostRequest();

        $store = CommercePlugin::getInstance()->getStores()->getPrimaryStore();
        $methodId = (int)$this->request->getRequiredBodyParam('methodId');
        $categories = CommercePlugin::getInstance()->getShippingCategories()->getAllShippingCategories($store->id);
        $submitted = $this->request->getBodyParam('rates', []);

        foreach ($submitted as $zoneId => $categoryRates) {
            $rule = $this->findRuleForZone($methodId, (int)$zoneId) ?? $this->createRule($methodId, (int)$zoneId, $store->id);

            $ruleCategories = [];
            foreach ($categories as $category) {
                $ruleCategories[$category->id] = new ShippingRuleCategory([
                    'shippingCategoryId' => $category->id,
                    'condition' => ShippingRuleCategoryRecord::CONDITION_ALLOW,
                    'perItemRate' => $this->parseMoney($categoryRates[$category->id] ?? null, $store->getCurrency()),
                ]);
            }
            $rule->setShippingRuleCategories($ruleCategories);

            if (!CommercePlugin::getInstance()->getShippingRules()->saveShippingRule($rule)) {
                return $this->asFailure(Craft::t('app', 'Couldn’t save shipping prices.'));
            }
        }

        return $this->asSuccess(Craft::t('app', 'Shipping prices saved.'));
    }

    /**
     * `forms.moneyField` doesn't post a plain scalar: its input name gets suffixed into
     * `[value]` and `[locale]` sub-keys for its JS currency widget, so casting the raw
     * posted value straight to float (a non-empty array casts to `1.0`) silently turned
     * every cell into 1.00. This mirrors how Commerce's own ShippingRulesController reads
     * the same macro's output.
     */
    private function parseMoney(mixed $input, string $currency): float {
        if (!is_array($input) || trim($input['value'] ?? '') === '') {
            return 0.0;
        }

        $money = MoneyHelper::toMoney($input + ['currency' => $currency]);

        return $money ? (float)MoneyHelper::toDecimal($money) : 0.0;
    }

    /**
     * Rules don't carry a zone ID directly (Commerce 5 matches the zone via a condition
     * rule on the order condition), so finding "the rule for this method+zone" means
     * scanning the method's rules for one whose zone condition is just this zone.
     */
    private function findRuleForZone(int $methodId, int $zoneId): ?ShippingRule {
        foreach (CommercePlugin::getInstance()->getShippingRules()->getAllShippingRulesByShippingMethodId($methodId) as $rule) {
            foreach ($rule->getOrderCondition()->getConditionRules() as $conditionRule) {
                if ($conditionRule instanceof ShippingAddressZoneConditionRule && in_array($zoneId, $conditionRule->getValues(), true)) {
                    return $rule;
                }
            }
        }

        return null;
    }

    private function createRule(int $methodId, int $zoneId, int $storeId): ShippingRule {
        $zone = CommercePlugin::getInstance()->getShippingZones()->getShippingZoneById($zoneId, $storeId);

        $zoneCondition = new ShippingAddressZoneConditionRule();
        $zoneCondition->setValues([$zoneId]);

        $orderCondition = new ShippingRuleOrderCondition();
        $orderCondition->storeId = $storeId;
        $orderCondition->addConditionRule($zoneCondition);

        $rule = new ShippingRule();
        $rule->storeId = $storeId;
        $rule->methodId = $methodId;
        $rule->name = $zone->name;
        $rule->setOrderCondition($orderCondition);

        return $rule;
    }
}
