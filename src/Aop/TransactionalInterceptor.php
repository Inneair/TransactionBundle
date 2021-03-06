<?php

/**
 * Copyright 2016 Inneair
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 *     http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 *
 * @license http://www.apache.org/licenses/LICENSE-2.0.html Apache-2.0
 */

namespace Inneair\TransactionBundle\Aop;

use CG\Proxy\MethodInvocation;
use CG\Proxy\MethodInterceptorInterface;
use Doctrine\Common\Annotations\Reader;
use Doctrine\ORM\EntityManagerInterface;
use Exception;
use Psr\Log\LoggerInterface;
use ReflectionMethod;
use Inneair\TransactionBundle\Annotation\Transactional;
use Inneair\TransactionBundle\DependencyInjection\Configuration;
use Symfony\Bridge\Doctrine\RegistryInterface;
use Symfony\Component\DependencyInjection\ContainerAwareInterface;
use Symfony\Component\DependencyInjection\ContainerAwareTrait;

/**
 * AOP advice for transaction management in services.
 */
class TransactionalInterceptor implements MethodInterceptorInterface, ContainerAwareInterface
{
    use ContainerAwareTrait;

    /**
     * Doctrine's entity manager registry.
     * @var RegistryInterface
     */
    private $entityManagerRegistry;
    /**
     * Annotations reader.
     * @var Reader
     */
    private $reader;
    /**
     * Logger.
     * @var LoggerInterface
     */
    private $logger;

    /**
     * Creates a method interceptor managing the @Transactional annotation.
     *
     * @param RegistryInterface $entityManagerRegistry Doctrine's entity manager registry.
     * @param Reader $reader Doctrine Annotation reader.
     * @param LoggerInterface $logger Logger.
     */
    public function __construct(RegistryInterface $entityManagerRegistry, Reader $reader, LoggerInterface $logger)
    {
        $this->entityManagerRegistry = $entityManagerRegistry;
        $this->reader = $reader;
        $this->logger = $logger;
    }

    /**
     * {@inheritDoc}
     *
     * @param MethodInvocation $method Current method invocation.
     */
    public function intercept(MethodInvocation $method)
    {
        $methodDefinition = $method->reflection;

        // Gets the Transactional annotation, if existing.
        $annotation = $this->getTransactionalAnnotation($methodDefinition);

        // The transactional policy is determined by the annotation, if found.
        // If missing, default behaviour is to do nothing (no transaction started).
        if ($annotation === null) {
            $policy = Transactional::NOT_REQUIRED;
        } elseif ($annotation->getPolicy() === null) {
            $policy = $this->container->getParameter(
                Configuration::ROOT_NODE_NAME . '.' . Configuration::DEFAULT_POLICY);
        } else {
            $policy = $annotation->getPolicy();
        }

        if (($policy === Transactional::NOT_REQUIRED) && ($annotation === null)) {
            // No annotation found: there is probably a bug in the pointcut class, because the interceptor should not
            // have been invoked.
            $this->logger->warning(
                'Transactional interceptor was invoked, but no annotation was found for method \'' .
                 $methodDefinition->getDeclaringClass()->getName() . '::' . $methodDefinition->getName() . '\''
            );
        }

        $transactionRequired = false;
        if ($annotation !== null) {
            // Determine if a transaction must be started.
            $transactionRequired = $this->isTransactionRequired(
                $policy,
                $this->getEntityManager()->getConnection()->isTransactionActive()
            );
        }

        $this->beforeMethodInvocation($transactionRequired);
        try {
            // Invokes the method.
            $this->logger->debug(
                $methodDefinition->getDeclaringClass()->getName() . '::' . $methodDefinition->getName()
            );
            $result = $method->proceed();
            $this->afterMethodInvocationSuccess($transactionRequired);
        } catch (Exception $e) {
            // Manage special exceptions (commit or rollback strategy).
            if ($annotation === null) {
                // At this point, it means there is no inner transaction context for the method.
                $noRollbackExceptions = null;
            } elseif ($annotation->getNoRollbackExceptions() === null) {
                // No exceptions set in the annotation (even if the parameter was found), use the default configuration.
                $noRollbackExceptions = $this->container->getParameter(
                    Configuration::ROOT_NODE_NAME . '.' . Configuration::NO_ROLLBACK_EXCEPTIONS
                );
            } else {
                // Use the annotation parameter.
                $noRollbackExceptions = $annotation->getNoRollbackExceptions();
            }
            $this->afterMethodInvocationFailure($transactionRequired, $e, $noRollbackExceptions);
        }

        return $result;
    }

    /**
     * Get the entity manager
     *
     * @return EntityManagerInterface
     */
    protected function getEntityManager()
    {
        return $this->entityManagerRegistry->getManager();
    }

    /**
     * Performs additional process after the intercepted method call was performed with a failure.
     *
     * @param boolean $transactionRequired If a new transaction was required.
     * @param Exception $e Exception to throw at the end of the additional process.
     * @param string[] $noRollbackExceptions An array of exceptions that shall not lead to a transaction rollback.
     * @throws Exception At the end of the additional process (given exception).
     */
    protected function afterMethodInvocationFailure(
        $transactionRequired,
        Exception $e,
        array $noRollbackExceptions = null
    )
    {
        if ($transactionRequired) {
            if ($this->isRollbackEnabled($e, $noRollbackExceptions)) {
                // Rollbacks the transaction.
                $this->logger->debug('Exception ' . get_class($e) . ' causes rollback');
                $this->rollback();
            } else {
                // Commits the transaction.
                $this->logger->debug('No rollback for exception ' . get_class($e));
                $this->commit();
            }
        }

        throw $e;
    }

    /**
     * Performs additional process after the intercepted method call was performed successfully.
     *
     * @param boolean $transactionRequired If a new transaction was required.
     */
    protected function afterMethodInvocationSuccess($transactionRequired)
    {
        if ($transactionRequired) {
            // Commits the transaction.
            $this->commit();
        }
    }

    /**
     * Performs additional process before the intercepted method call is performed.
     *
     * @param boolean $transactionRequired If a new transaction is required.
     */
    protected function beforeMethodInvocation($transactionRequired)
    {
        if ($transactionRequired) {
            // Starts a transaction.
            $this->beginTransaction();
        }
    }

    /**
     * Starts a transaction on the underlying database connection of a Doctrine's entity manager.
     */
    protected function beginTransaction()
    {
        $this->logger->debug(static::class . '::beginTransaction');
        $this->getEntityManager()->beginTransaction();
    }

    /**
     * Commits the pending transaction on the underlying database connection of a Doctrine's entity manager.
     */
    protected function commit()
    {
        $this->logger->debug(static::class . '::commit');
        $this->getEntityManager()->flush();
        $this->getEntityManager()->commit();
    }

    /**
     * Closes the entity manager, and rollbacks the pending transaction on the underlying database connection of a
     * Doctrine's entity manager with the given name. This method also resets the manager, so as it can be recreated for
     * a new transaction, when needed.
     */
    protected function rollback()
    {
        $this->logger->debug(static::class . '::rollback');
        $this->getEntityManager()->rollback();

        // Close the manager if there is no transaction started.
        if (!$this->getEntityManager()->getConnection()->isTransactionActive()) {
            $this->getEntityManager()->close();
            $this->entityManagerRegistry->resetManager();
        }
    }

    /**
     * Gets the Transactional annotation, if any, looking at method level as a priority, then at class level.
     *
     * @param ReflectionMethod $method Method definition.
     * @return Transactional The transaction annotation, or <code>null</code> if not found.
     */
    protected function getTransactionalAnnotation(ReflectionMethod $method)
    {
        $annotation = $this->reader->getMethodAnnotation($method, Transactional::class);
        if ($annotation === null) {
            // If there is no method-level annotation, gets class-level annotation.
            $annotation = $this->reader->getClassAnnotation($method->getDeclaringClass(), Transactional::class);
        }
        return $annotation;
    }

    /**
     * Tells whether a transaction must be started, depending on the configured policy and the current TX status.
     *
     * @param int $policy One of the policy defined in the Transactional annotation.
     * @param boolean $isTransactionActive Whether a transaction is already active when invoking a method.
     * @return boolean <code>true</code> if a new transaction is required, <code>false</code> otherwise.
     */
    protected function isTransactionRequired($policy, $isTransactionActive)
    {
        return ($policy === Transactional::NESTED) || (($policy === Transactional::REQUIRED) && !$isTransactionActive);
    }

    /**
     * Checks whether a rollback shall be executed for a given exception.
     *
     * @param Exception $e An exception.
     * @param string[] $noRollbackExceptions An array of exceptions that shall not lead to a transaction rollback.
     * @return boolean <code>true</code> if the rollback must be executed, e.g. this exception shall not be "ignored" by
     * the current transactional context.
     */
    protected function isRollbackEnabled(Exception $e, array $noRollbackExceptions = null)
    {
        $rollbackEnabled = true;
        if ($noRollbackExceptions !== null) {
            foreach ($noRollbackExceptions as $noRollbackException) {
                if (is_a($e, $noRollbackException)) {
                    $rollbackEnabled = false;
                    break;
                }
            }
        }

        return $rollbackEnabled;
    }
}
