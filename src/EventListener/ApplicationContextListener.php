<?php

namespace EasyCorp\Bundle\EasyAdminBundle\EventListener;

use Doctrine\Bundle\DoctrineBundle\Registry;
use Doctrine\Common\Persistence\ObjectManager;
use EasyCorp\Bundle\EasyAdminBundle\Contacts\CrudControllerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Context\ApplicationContext;
use EasyCorp\Bundle\EasyAdminBundle\Contracts\DashboardControllerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Contracts\ItemCollectionBuilderInterface;
use EasyCorp\Bundle\EasyAdminBundle\Dto\AssetDto;
use EasyCorp\Bundle\EasyAdminBundle\Dto\CrudDto;
use EasyCorp\Bundle\EasyAdminBundle\Dto\CrudPageDto;
use EasyCorp\Bundle\EasyAdminBundle\Dto\EntityDto;
use EasyCorp\Bundle\EasyAdminBundle\Exception\EntityNotFoundException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Controller\ControllerResolverInterface;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Twig\Environment;

/**
 * Initializes the ApplicationContext variable and stores it as a request attribute.
 *
 * @author Javier Eguiluz <javier.eguiluz@gmail.com>
 * @author Maxime Steinhausser <maxime.steinhausser@gmail.com>
 * @author Yonel Ceruto <yonelceruto@gmail.com>
 */
class ApplicationContextListener
{
    private $controllerResolver;
    private $doctrine;
    private $twig;
    private $tokenStorage;
    private $menuBuilder;
    private $actionBuilder;

    public function __construct(ControllerResolverInterface $controllerResolver, Registry $doctrine, Environment $twig, ?TokenStorageInterface $tokenStorage, ItemCollectionBuilderInterface $menuBuilder, $actionBuilder)
    {
        $this->controllerResolver = $controllerResolver;
        $this->doctrine = $doctrine;
        $this->twig = $twig;
        $this->tokenStorage = $tokenStorage;
        $this->menuBuilder = $menuBuilder;
        $this->actionBuilder = $actionBuilder;
    }

    public function onKernelController(ControllerEvent $event): void
    {
        if (!$this->isDashboardController($event->getController())) {
            return;
        }

        $crudControllerCallable = $this->getCrudController($event->getRequest());
        $crudControllerInstance = $crudControllerCallable[0];

        $this->createApplicationContext($event, $crudControllerInstance);
        $applicationContext = $this->getApplicationContext($event);
        // this makes the ApplicationContext available in all templates as a short named variable
        $this->twig->addGlobal('ea', $applicationContext);

        if (null !== $crudControllerInstance) {
            // Changes the controller associated to the current request to execute the
            // CRUD controller and page requested via the dashboard menu and actions
            $event->setController($crudControllerCallable);
        }
    }

    private function isDashboardController(callable $controller): bool
    {
        // if the controller is defined in a class, $controller is an array
        // otherwise do nothing because it's a Closure (rare but possible in Symfony)
        if (!\is_array($controller)) {
            return false;
        }

        $controllerInstance = $controller[0];

        // If the controller does not implement EasyAdmin's DashboardControllerInterface,
        // assume that the request is not related to EasyAdmin
        if (!$controllerInstance instanceof DashboardControllerInterface) {
            return false;
        }

        return true;
    }

    private function getCrudController(Request $request): ?callable
    {
        $crudControllerFqcn = $request->query->get('crudController');
        $crudPage = $request->query->get('crudPage');

        if (null === $crudControllerFqcn || null === $crudPage) {
            return null;
        }

        $crudRequest = $request->duplicate();
        $crudRequest->attributes->set('_controller', [$crudControllerFqcn, $crudPage]);
        $crudControllerCallable = $this->controllerResolver->getController($crudRequest);

        if (false === $crudControllerCallable) {
            throw new NotFoundHttpException(sprintf('Unable to find the controller "%s::%s".', $crudControllerFqcn, $crudPage));
        }

        if (!is_array($crudControllerCallable)) {
            return null;
        }

        if (!$crudControllerCallable[0] instanceof CrudControllerInterface) {
            return null;
        }

        return $crudControllerCallable;
    }

    private function createApplicationContext(ControllerEvent $event, ?CrudControllerInterface $crudControllerInstance): void
    {
        // creating the context is expensive, so it's created once and stored in the request
        // if the current request already has an ApplicationContext object, do nothing
        if ($this->getApplicationContext($event) instanceof ApplicationContext) {
            return;
        }

        $request = $event->getRequest();
        $dashboardControllerInstance = $event->getController()[0];
        $crudPage = $request->query->get('crudPage');
        $entityId = $request->query->get('entityId');

        $dashboardController = $this->getDashboard($event);
        $assetDto = $this->getAssets($dashboardControllerInstance, $crudControllerInstance);
        $crudDto = $this->getCrudConfig($crudControllerInstance);
        $crudPageDto = $this->getPageConfig($crudControllerInstance, $crudPage);
        $entityDto = null === $crudDto ? null : $this->getDoctrineEntity($crudDto, $entityId);

        $applicationContext = new ApplicationContext($request, $this->tokenStorage, $dashboardController, $this->menuBuilder, $this->actionBuilder, $assetDto, $crudDto, $crudPageDto, $entityDto);
        $this->setApplicationContext($event, $applicationContext);
    }

    private function getApplicationContext(ControllerEvent $event): ?ApplicationContext
    {
        return $event->getRequest()->attributes->get(ApplicationContext::ATTRIBUTE_KEY);
    }

    private function setApplicationContext(ControllerEvent $event, ApplicationContext $applicationContext): void
    {
        $event->getRequest()->attributes->set(ApplicationContext::ATTRIBUTE_KEY, $applicationContext);
    }

    private function getDashboard(ControllerEvent $event): DashboardControllerInterface
    {
        /** @var \EasyCorp\Bundle\EasyAdminBundle\Contracts\DashboardControllerInterface $dashboard */
        $dashboard = $event->getController()[0];

        return $dashboard;
    }

    private function getAssets(DashboardControllerInterface $dashboardController, ?CrudControllerInterface $crudController): AssetDto
    {
        $dashboardAssets = $dashboardController->configureAssets()->getAsDto();

        if (null === $crudController) {
            return $dashboardAssets;
        }

        $crudAssets = $crudController->configureAssets()->getAsDto();

        return $dashboardAssets->mergeWith($crudAssets);
    }

    private function getCrudConfig(?CrudControllerInterface $crudController): ?CrudDto
    {
        if (null === $crudController) {
            return null;
        }

        return $crudController->configureCrud()->getAsDto();
    }

    /**
     * @return CrudPageDto|null
     */
    private function getPageConfig(?CrudControllerInterface $crudController, ?string $crudPage)
    {
        $pageConfigMethodName = 'configure'.ucfirst($crudPage).'Page';
        if (null === $crudController || !method_exists($crudController, $pageConfigMethodName)) {
            return null;
        }

        return $crudController->{$pageConfigMethodName}()->getAsDto();
    }

    private function getDoctrineEntity(CrudDto $crudDto, $entityId): ?EntityDto
    {
        if (null === $entityId || null === $entityFqcn = $crudDto->getEntityClass()) {
            return null;
        }

        $entityManager = $this->getEntityManager($entityFqcn);
        $entityInstance = $this->getEntityInstance($entityManager, $entityFqcn, $entityId);
        $entityMetadata = $entityManager->getClassMetadata($entityFqcn);
        if (1 !== count($entityMetadata->getIdentifierFieldNames())) {
            throw new \RuntimeException('EasyAdmin does not support Doctrine entities with composite primary keys.');
        }

        return new EntityDto($entityMetadata, $entityInstance, $entityId);
    }

    private function getEntityManager(string $entityClass): ObjectManager
    {
        if (null === $entityManager = $this->doctrine->getManagerForClass($entityClass)) {
            throw new \RuntimeException(sprintf('There is no Doctrine Entity Manager defined for the "%s" class', $entityClass));
        }

        return $entityManager;
    }

    private function getEntityInstance(ObjectManager $entityManager, string $entityClass, $entityIdValue)
    {
        if (null === $entityInstance = $entityManager->getRepository($entityClass)->find($entityIdValue)) {
            $entityIdName = $entityManager->getClassMetadata($entityClass)->getIdentifierFieldNames()[0];

            throw new EntityNotFoundException(['entity_name' => $entityClass, 'entity_id_name' => $entityIdName, 'entity_id_value' => $entityIdValue]);
        }

        return $entityInstance;
    }
}
