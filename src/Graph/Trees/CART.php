<?php

namespace Rubix\ML\Graph\Trees;

use Rubix\ML\Estimator;
use Rubix\ML\Datasets\Dataset;
use Rubix\ML\Datasets\Labeled;
use Rubix\ML\Graph\Nodes\Leaf;
use Rubix\ML\Graph\Nodes\Comparison;
use Rubix\ML\Graph\Nodes\BinaryNode;
use InvalidArgumentException;

/**
 * CART
 *
 * Classification and Regression Tree or *CART* is a binary tree that uses
 * comparision (*decision*) nodes at every split in the training data to
 * locate a leaf node.
 *
 * [1] W. Y. Loh. (2011). Classification and Regression Trees.
 *
 * @category    Machine Learning
 * @package     Rubix/ML
 * @author      Andrew DalPino
 */
abstract class CART implements Tree
{
    /**
     * The root node of the tree.
     *
     * @var \Rubix\ML\Graph\Nodes\Comparison|null
     */
    protected $root;

    /**
     * The maximum depth of a branch before it is forced to terminate.
     *
     * @var int
     */
    protected $maxDepth;

    /**
     * The maximum number of samples that a leaf node can contain.
     *
     * @var int
     */
    protected $maxLeafSize;

    /**
     * The number of times the tree has split. i.e. a decision is made.
     *
     * @var int
     */
    protected $splits;

    /**
     * @param  int  $maxDepth
     * @param  int  $maxLeafSize
     * @throws \InvalidArgumentException
     * @return void
     */
    public function __construct(int $maxDepth = PHP_INT_MAX, int $maxLeafSize = 3)
    {
        if ($maxDepth < 1) {
            throw new InvalidArgumentException('A tree cannot have depth less'
                . ' than 1.');
        }

        if ($maxLeafSize < 1) {
            throw new InvalidArgumentException('At least one sample is required'
                . ' to create a leaf.');
        }

        $this->maxDepth = $maxDepth;
        $this->maxLeafSize = $maxLeafSize;
        $this->splits = 0;
    }

    /**
     * Greedy algorithm to choose the best split for a given dataset.
     *
     * @param  \Rubix\ML\Datasets\Labeled  $dataset
     * @param  int  $depth
     * @return \Rubix\ML\Graph\Nodes\Comparison
     */
    abstract protected function findBestSplit(Labeled $dataset, int $depth) : Comparison;

    /**
     * Terminate the branch by selecting the most likely outcome as the
     * prediction.
     *
     * @param  \Rubix\ML\Datasets\Labeled  $dataset
     * @param  int  $depth
     * @return \Rubix\ML\Graph\Nodes\BinaryNode
     */
    abstract protected function terminate(Labeled $dataset, int $depth) : BinaryNode;

    /**
     * The complexity of the decision tree i.e. the number of splits.
     *
     * @return int
     */
    public function complexity() : int
    {
        return $this->splits;
    }

    /**
     * Return the root node of the tree.
     * 
     * @return \Rubix\ML\Graph\Nodes\Comparison|null
     */
    public function root() : ?Comparison
    {
        return $this->root;
    }

    /**
     * Insert a root node into the tree and recursively split the training data
     * until a terminating condition is met.
     *
     * @param  \Rubix\ML\Datasets\Labeled  $dataset
     * @return void
     */
    public function grow(Labeled $dataset) : void
    {
        $this->root = $this->findBestSplit($dataset, 0);

        $this->splits = 1;

        $this->split($this->root, 1);
    }

    /**
     * Recursive function to split the training data adding comparison nodes along
     * the way. The terminating conditions are a) split would make node
     * responsible for less values than $maxLeafSize or b) the max depth of the
     * branch has been reached.
     *
     * @param  \Rubix\ML\Graph\Nodes\Comparison  $current
     * @param  int  $depth
     * @return void
     */
    protected function split(Comparison $current, int $depth) : void
    {
        list($left, $right) = $current->groups();

        $current->cleanup();

        if ($left->empty() or $right->empty()) {
            $node = $this->terminate($left->merge($right), $depth);

            $current->attachLeft($node);
            $current->attachRight($node);
            return;
        }

        if ($depth >= $this->maxDepth) {
            $current->attachLeft($this->terminate($left, $depth));
            $current->attachRight($this->terminate($right, $depth));
            return;
        }

        if ($left->numRows() > $this->maxLeafSize) {
            $node = $this->findBestSplit($left, $depth);

            $current->attachLeft($node);

            $this->splits++;

            $this->split($node, $depth + 1);
        } else {
            $current->attachLeft($this->terminate($left, $depth));
        }

        if ($right->numRows() > $this->maxLeafSize) {
            $node = $this->findBestSplit($right, $depth);

            $current->attachRight($node);

            $this->splits++;

            $this->split($node, $depth + 1);
        } else {
            $current->attachRight($this->terminate($right, $depth));
        }
    }

    /**
     * Search the tree for a leaf node.
     *
     * @param  array  $sample
     * @return \Rubix\ML\Graph\Nodes\BinaryNode|null
     */
    public function search(array $sample) : ?BinaryNode
    {
        $current = $this->root;

        while ($current) {
            if ($current instanceof Comparison) {
                $value = $current->value();

                if (is_string($value)) {
                    if ($sample[$current->column()] === $value) {
                        $current = $current->left();
                    } else {
                        $current = $current->right();
                    }
                } else {
                    if ($sample[$current->column()] < $value) {
                        $current = $current->left();
                    } else {
                        $current = $current->right();
                    }
                }

                continue 1;
            }

            if ($current instanceof Leaf) {
                return $current;
            }
        }

        return null;
    }

    /**
     * Return an array indexed by column number that contains the normalized
     * importance score of that column in determining the overall prediction.
     *
     * @return array
     */
    public function featureImportances() : array
    {
        if (is_null($this->root)) {
            return [];
        }

        $nodes = $this->dump($this->root);

        $importances = [];

        foreach ($nodes as $node) {
            if ($node instanceof Comparison) {
                $index = $node->column();

                if (isset($importances[$index])) {
                    $importances[$index] += $node->impurityDecrease();
                } else {
                    $importances[$index] = $node->impurityDecrease();
                }
            }
        }

        $total = array_sum($importances) ?: Estimator::EPSILON;

        foreach ($importances as &$importance) {
            $importance /= $total;
        }

        arsort($importances);

        return $importances;
    }

    /**
     * Return an array of all the nodes in the tree starting at a
     * given node.
     *
     * @param  \Rubix\ML\Graph\Nodes\BinaryNode  $current
     * @return array
     */
    public function dump(BinaryNode $current) : array
    {
        if ($current instanceof Leaf) {
            return [$current];
        }

        $left = $right = [];

        $node = $current->left();

        if ($node instanceof BinaryNode) {
            $left = $this->dump($node);
        }

        $node = $current->right();

        if ($node instanceof BinaryNode) {
            $right = $this->dump($node);
        }

        return array_merge([$current], $left, $right);
    }

    /**
     * Is the tree bare?
     *
     * @return bool
     */
    public function bare() : bool
    {
        return is_null($this->root);
    }
}
