<?php declare(strict_types=1);

namespace Rector\Rector\Architecture\RepositoryAsService;

use Nette\Utils\Strings;
use PhpParser\BuilderFactory;
use PhpParser\Node;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\Expression;
use Rector\Builder\Class_\VariableInfo;
use Rector\Builder\ConstructorMethodBuilder;
use Rector\Builder\PropertyBuilder;
use Rector\Contract\Bridge\EntityForDoctrineRepositoryProviderInterface;
use Rector\Exception\Bridge\RectorProviderException;
use Rector\Node\Attribute;
use Rector\Node\NodeFactory;
use Rector\Rector\AbstractRector;
use Rector\RectorDefinition\CodeSample;
use Rector\RectorDefinition\RectorDefinition;

final class MoveRepositoryFromParentToConstructorRector extends AbstractRector
{
    /**
     * @var PropertyBuilder
     */
    private $propertyBuilder;

    /**
     * @var ConstructorMethodBuilder
     */
    private $constructorMethodBuilder;

    /**
     * @var NodeFactory
     */
    private $nodeFactory;

    /**
     * @var EntityForDoctrineRepositoryProviderInterface
     */
    private $entityForDoctrineRepositoryProvider;

    /**
     * @var BuilderFactory
     */
    private $builderFactory;

    public function __construct(
        PropertyBuilder $propertyBuilder,
        ConstructorMethodBuilder $constructorMethodBuilder,
        NodeFactory $nodeFactory,
        EntityForDoctrineRepositoryProviderInterface $entityForDoctrineRepositoryProvider,
        BuilderFactory $builderFactory
    ) {
        $this->propertyBuilder = $propertyBuilder;
        $this->constructorMethodBuilder = $constructorMethodBuilder;
        $this->nodeFactory = $nodeFactory;
        $this->entityForDoctrineRepositoryProvider = $entityForDoctrineRepositoryProvider;
        $this->builderFactory = $builderFactory;
    }

    public function isCandidate(Node $node): bool
    {
        if (! $node instanceof Class_) {
            return false;
        }

        if (! $node->extends) {
            return false;
        }

        $parentClassName = $node->getAttribute(Attribute::PARENT_CLASS_NAME);
        if ($parentClassName !== 'Doctrine\ORM\EntityRepository') {
            return false;
        }

        $className = $node->getAttribute(Attribute::CLASS_NAME);

        return Strings::endsWith($className, 'Repository');
    }

    public function getDefinition(): RectorDefinition
    {
        return new RectorDefinition('Turns parent EntityRepository class to constructor dependency', [
            new CodeSample(
                <<<'CODE_SAMPLE'
namespace App\Repository;

use Doctrine\ORM\EntityRepository;

final class PostRepository extends EntityRepository
{
}
CODE_SAMPLE
                ,
                <<<'CODE_SAMPLE'
namespace App\Repository;

use App\Entity\Post;
use Doctrine\ORM\EntityRepository;

final class PostRepository
{
    /**
     * @var \Doctrine\ORM\EntityRepository
     */
    private $repository;
    public function __construct(\Doctrine\ORM\EntityManager $entityManager)
    {
        $this->repository = $entityManager->getRepository(\App\Entity\Post::class);
    }
}
CODE_SAMPLE
            ),
        ]);
    }

    /**
     * @param Class_ $node
     */
    public function refactor(Node $node): ?Node
    {
        // remove parent class
        $node->extends = null;

        // add $repository property
        $propertyInfo = VariableInfo::createFromNameAndTypes('repository', ['Doctrine\ORM\EntityRepository']);
        $this->propertyBuilder->addPropertyToClass($node, $propertyInfo);

        // add $entityManager and assign to constuctor
        $this->constructorMethodBuilder->addParameterAndAssignToConstructorArgumentsOfClass(
            $node,
            VariableInfo::createFromNameAndTypes('entityManager', ['Doctrine\ORM\EntityManager']),
            $this->createRepositoryAssign($node)
        );

        return $node;
    }

    /**
     * Creates:
     * "$this->repository = $entityManager->getRepository()"
     */
    private function createRepositoryAssign(Class_ $classNode): Expression
    {
        $repositoryClassName = (string) $classNode->getAttribute(Attribute::CLASS_NAME);
        $entityClassName = $this->entityForDoctrineRepositoryProvider->provideEntityForRepository($repositoryClassName);

        if ($entityClassName === null) {
            throw new RectorProviderException(sprintf(
                'An entity was not provided for "%s" repository by your "%s" class.',
                $repositoryClassName,
                get_class($this->entityForDoctrineRepositoryProvider)
            ));
        }

        $entityClassConstantReferenceNode = $this->nodeFactory->createClassConstantReference($entityClassName);

        $getRepositoryMethodCallNode = $this->builderFactory->methodCall(
            new Variable('entityManager'),
            'getRepository',
            [$entityClassConstantReferenceNode]
        );

        return $this->nodeFactory->createPropertyAssignmentWithExpr('repository', $getRepositoryMethodCallNode);
    }
}
