<?php

namespace Oro\Bundle\InstallerBundle\Process\Step;

use Sylius\Bundle\FlowBundle\Process\Context\ProcessContextInterface;

class ConfigureStep extends AbstractStep
{
    public function displayAction(ProcessContextInterface $context)
    {
        if ($this->container->hasParameter('installed') && $this->container->getParameter('installed')) {
            return $this->redirect($this->generateUrl('oro_default'));
        }

        return $this->render(
            'OroInstallerBundle:Process/Step:configure.html.twig',
            array(
                'form' => $this->createConfigurationForm()->createView()
            )
        );
    }

    public function forwardAction(ProcessContextInterface $context)
    {
        set_time_limit(600);

        $form = $this->createConfigurationForm();

        $form->handleRequest($this->getRequest());

        if ($form->isValid()) {
            $data = $form->getData();

            $context->getStorage()->set(
                'fullDatabase',
                $form->has('database') &&
                $form->get('database')->has('oro_installer_database_drop_full') &&
                $form->get('database')->get('oro_installer_database_drop_full')->getData()
            );

            $this->get('oro_installer.yaml_persister')->dump($data);

            return $this->complete();
        }

        return $this->render(
            'OroInstallerBundle:Process/Step:configure.html.twig',
            array(
                'form' => $form->createView()
            )
        );
    }

    protected function createConfigurationForm()
    {
        $data = $this->get('oro_installer.yaml_persister')->parse();

        return $this->createForm('oro_installer_configuration', empty($data) ? null : $data);
    }
}
