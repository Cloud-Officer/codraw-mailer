<?php

namespace Draw\Component\Mailer\DependencyInjection\Compiler;

use Draw\Component\Core\Reflection\ReflectionAccessor;
use Draw\Component\Core\Reflection\ReflectionExtractor;
use Draw\Component\Mailer\EmailComposer;
use Draw\Component\Mailer\EmailWriter\EmailWriterInterface;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\Compiler\ServiceLocatorTagPass;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Exception\ServiceNotFoundException;
use Symfony\Component\DependencyInjection\Reference;

class EmailWriterCompilerPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        try {
            $emailWriterListenerDefinition = $container->findDefinition(EmailComposer::class);
        } catch (ServiceNotFoundException) {
            return;
        }

        $writers = [];
        foreach ($container->findTaggedServiceIds(EmailWriterInterface::class) as $id => $tags) {
            $writers[$id] = new Reference($id);
            $definition = $container->getDefinition($id);
            $class = $container->getParameterBag()->resolveValue($definition->getClass()) ?: $id;

            if (!method_exists($class, 'getForEmails')) {
                throw new \RuntimeException(\sprintf('The class "%s" of the service "%s" tagged "%s" must implement a "getForEmails" method.', $class, $id, EmailWriterInterface::class));
            }

            $forEmails = ReflectionAccessor::callMethod($class, 'getForEmails');
            foreach ($forEmails as $methodName => $priority) {
                if (\is_int($methodName)) {
                    $methodName = $priority;
                    $priority = 0;
                }

                if (!method_exists($class, $methodName)) {
                    throw new \RuntimeException(\sprintf('The method "%s::%s()" returned by "%s::getForEmails()" for the service "%s" does not exist.', $class, $methodName, $class, $id));
                }

                $parameters = (new \ReflectionMethod($class, $methodName))->getParameters();

                if (!isset($parameters[0])) {
                    throw new \RuntimeException(\sprintf('The method "%s::%s()" used as an email writer by the service "%s" must have at least one parameter.', $class, $methodName, $id));
                }

                $emailTypes = ReflectionExtractor::getClasses($parameters[0]->getType());

                foreach ($emailTypes as $emailType) {
                    $emailWriterListenerDefinition
                        ->addMethodCall('addWriter', [$emailType, $id, $methodName, $priority])
                    ;
                }
            }
        }

        $emailWriterListenerDefinition
            ->setArgument(
                '$serviceLocator',
                ServiceLocatorTagPass::register($container, $writers)
            )
        ;
    }
}
