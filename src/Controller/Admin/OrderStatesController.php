<?php

declare(strict_types=1);

namespace PrestaShop\Module\PPVenipak\Controller\Admin;

use Configuration;
use Context;
use PrestaShop\PrestaShop\Core\Form\FormHandlerInterface;
use PrestaShopBundle\Controller\Admin\FrameworkBundleAdminController;
use PrestaShopBundle\Security\Attribute\AdminSecurity;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class OrderStatesController extends FrameworkBundleAdminController
{
    #[AdminSecurity("is_granted('read', request.get('_legacy_controller'))")]
    public function index(
        Request $request,
        #[Autowire(service: 'prestashop.module.ppvenipak.form.order_states_handler')]
        FormHandlerInterface $formHandler,
    ): Response {
        $form = $formHandler->getForm();
        $form->handleRequest($request);

        if ($request->isMethod('POST') && $form->isSubmitted() && $form->isValid()) {
            $errors = $formHandler->save($form->getData());

            if (empty($errors)) {
                $this->addFlash('success', $this->trans('Order-state mapping saved.', 'Modules.Ppvenipak.Admin'));
            } else {
                $this->flashErrors($errors);
            }

            return $this->redirectToRoute('ps_ppvenipak_order_states');
        }

        return $this->render('@Modules/ppvenipak/views/templates/admin/order_states.html.twig', [
            'form' => $form->createView(),
            'cron' => $this->buildCronInfo(),
        ]);
    }

    /**
     * Build the URL + sample shell command the merchant can drop into
     * their crontab. Generates a token on first access if none exists yet
     * — saves the merchant a step and means the URL is always usable.
     *
     * @return array{url: string, command: string, token: string, lookback_days: int}
     */
    private function buildCronInfo(): array
    {
        $token = (string) Configuration::get('PPVENIPAK_CRON_TOKEN');
        if ($token === '') {
            $token = bin2hex(random_bytes(16));
            Configuration::updateValue('PPVENIPAK_CRON_TOKEN', $token);
        }

        $link = Context::getContext()->link;
        $url = $link->getModuleLink('ppvenipak', 'cron', [
            'action' => 'tracking',
            'token' => $token,
        ], true);

        // -q -O- silences wget output; redirecting both stdout and stderr
        // to /dev/null keeps the crontab quiet so merchants don't get
        // hourly mail. Wrapping the URL in single quotes guards against
        // shell expansion of `&` between query params.
        $command = sprintf("wget -q -O- '%s' >/dev/null 2>&1", $url);

        return [
            'url' => $url,
            'command' => $command,
            'token' => $token,
            'lookback_days' => 7,
        ];
    }
}
