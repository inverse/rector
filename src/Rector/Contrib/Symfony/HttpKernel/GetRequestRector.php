<?php declare(strict_types=1);

namespace Rector\Rector\Contrib\Symfony\HttpKernel;

use PhpParser\Node;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Stmt\ClassMethod;
use Rector\Node\Attribute;
use Rector\Node\NodeFactory;
use Rector\NodeAnalyzer\Contrib\Symfony\ControllerMethodAnalyzer;
use Rector\NodeAnalyzer\MethodCallAnalyzer;
use Rector\NodeTraverserQueue\BetterNodeFinder;
use Rector\Rector\AbstractRector;
use Rector\RectorDefinition\CodeSample;
use Rector\RectorDefinition\RectorDefinition;

/**
 * Converts all:
 *     public action()
 *     {
 *         $this->getRequest()->...();
 *
 * into:
 *     public action(Request $request)
 *     {
 *         $request->...();
 *     }
 */
final class GetRequestRector extends AbstractRector
{
    /**
     * @var ControllerMethodAnalyzer
     */
    private $controllerMethodAnalyzer;

    /**
     * @var NodeFactory
     */
    private $nodeFactory;

    /**
     * @var MethodCallAnalyzer
     */
    private $methodCallAnalyzer;

    /**
     * @var BetterNodeFinder
     */
    private $betterNodeFinder;

    public function __construct(
        ControllerMethodAnalyzer $controllerMethodAnalyzer,
        MethodCallAnalyzer $methodCallAnalyzer,
        NodeFactory $nodeFactory,
        BetterNodeFinder $betterNodeFinder
    ) {
        $this->controllerMethodAnalyzer = $controllerMethodAnalyzer;
        $this->nodeFactory = $nodeFactory;
        $this->methodCallAnalyzer = $methodCallAnalyzer;
        $this->betterNodeFinder = $betterNodeFinder;
    }

    public function getDefinition(): RectorDefinition
    {
        return new RectorDefinition(
            'Turns fetching of dependencies via $this->get() to constructor injection in Command and Controller in Symfony',
            [
                new CodeSample(
                    <<<'CODE_SAMPLE'
class MyCommand extends ContainerAwareCommand
{
    public function someMethod()
    {
        // ...
        $this->get('some_service');
    }
}
CODE_SAMPLE
                    ,
                    <<<'CODE_SAMPLE'
class MyCommand extends Command
{
    public function __construct(SomeService $someService)
    {
        $this->someService = $someService;
    }

    public function someMethod()
    {
        $this->someService;
    }
}
CODE_SAMPLE
                ),
            ]
        );
    }

    public function isCandidate(Node $node): bool
    {
        if ($this->isActionWithGetRequestInBody($node)) {
            return true;
        }

        return $this->isGetRequestInAction($node);
    }

    /**
     * @param ClassMethod|MethodCall $classMethodOrMethodCallNode
     */
    public function refactor(Node $classMethodOrMethodCallNode): ?Node
    {
        if ($classMethodOrMethodCallNode instanceof ClassMethod) {
            $requestParam = $this->nodeFactory->createParam('request', 'Symfony\Component\HttpFoundation\Request');

            $classMethodOrMethodCallNode->params[] = $requestParam;

            return $classMethodOrMethodCallNode;
        }

        return $this->nodeFactory->createVariable('request');
    }

    private function isActionWithGetRequestInBody(Node $node): bool
    {
        if (! $this->controllerMethodAnalyzer->isAction($node)) {
            return false;
        }

        return (bool) $this->betterNodeFinder->find($node, function (Node $node) {
            return $this->methodCallAnalyzer->isMethod($node, 'getRequest');
        });
    }

    private function isGetRequestInAction(Node $node): bool
    {
        if (! $this->methodCallAnalyzer->isMethod($node, 'getRequest')) {
            return false;
        }

        $methodNode = $node->getAttribute(Attribute::METHOD_NODE);

        return $this->controllerMethodAnalyzer->isAction($methodNode);
    }
}
