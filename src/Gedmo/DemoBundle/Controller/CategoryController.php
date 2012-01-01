<?php
namespace Gedmo\DemoBundle\Controller;

use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Template;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Method;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\ParamConverter;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Gedmo\DemoBundle\Entity\Category;
use Gedmo\DemoBundle\Form\CategoryType;
use Doctrine\ORM\Query;

class CategoryController extends Controller
{
    /**
     * @Route("/", name="demo_category_list")
     * @Template
     */
    public function indexAction()
    {
        $em = $this->get('doctrine.orm.entity_manager');
        $dql = <<<____SQL
            SELECT c
            FROM GedmoDemoBundle:Category c
            ORDER BY c.lft ASC
____SQL;
        $q = $em->createQuery($dql);
        $q->setHint(
            Query::HINT_CUSTOM_OUTPUT_WALKER,
            'Gedmo\\Translatable\\Query\\TreeWalker\\TranslationWalker'
        );
        $q->setHint(
            \Gedmo\Translatable\TranslationListener::HINT_INNER_JOIN,
            $this->get('session')->get('gedmo.trans.inner_join', false)
        );

        $paginator = $this->get('knp_paginator');
        $categories = $paginator->paginate(
            $q,
            $this->get('request')->query->get('page', 1),
            10
        );

        $languages = $this->getLanguages();

        return compact('categories', 'languages');
    }

    /**
     * @Route("/fallback", name="demo_fallback_translation")
     */
    public function fallbackAction()
    {
        $s = $this->get('session');
        $fallback = $s->get('gedmo.trans.fallback', false);
        $fallback = $fallback ? false : true;
        $s->set('gedmo.trans.fallback', $fallback);
        return $this->redirect($this->generateUrl('demo_category_tree'));
    }

    /**
     * @Route("/inner-strategy", name="demo_inner_strategy")
     */
    public function innerAction()
    {
        $s = $this->get('session');
        $strategy = $s->get('gedmo.trans.inner_join', false);
        $strategy = $strategy ? false : true;
        $s->set('gedmo.trans.inner_join', $strategy);
        return $this->redirect($this->generateUrl('demo_category_tree'));
    }

    /**
     * @Route("/tree", name="demo_category_tree")
     * @Template
     */
    public function treeAction()
    {
        $em = $this->get('doctrine.orm.entity_manager');
        $repo = $em->getRepository('Gedmo\DemoBundle\Entity\Category');

        $self = &$this;
        $options = array(
            'decorate' => true,
            'nodeDecorator' => function($node) use (&$self) {
                $linkUp = '<a href="' . $self->generateUrl('demo_category_move_up', array('id' => $node['id'])) . '">Up</a>';
                $linkDown = '<a href="' . $self->generateUrl('demo_category_move_down', array('id' => $node['id'])) . '">Down</a>';
                $linkNode = '<a href="' . $self->generateUrl('demo_category_show', array('slug' => $node['slug']))
                    . '">' . $node['title'] . '</a>'
                ;
                return $linkNode . '&nbsp;&nbsp;&nbsp;' . $linkUp . '&nbsp;' . $linkDown;
            }
        );
        $query = $em
            ->createQueryBuilder()
            ->select('node')
            ->from('Gedmo\DemoBundle\Entity\Category', 'node')
            ->orderBy('node.root, node.lft', 'ASC')
            ->getQuery()
        ;
        $query->setHint(
            Query::HINT_CUSTOM_OUTPUT_WALKER,
            'Gedmo\\Translatable\\Query\\TreeWalker\\TranslationWalker'
        );
        $query->setHint(
            \Gedmo\Translatable\TranslationListener::HINT_INNER_JOIN,
            $this->get('session')->get('gedmo.trans.inner_join', false)
        );
        $nodes = $query->getArrayResult();
        $tree = $repo->buildTree($nodes, $options);
        $languages = $this->getLanguages();
        $rootNodes = array_filter($nodes, function ($node) {
            return $node['level'] === 0;
        });

        return compact('tree', 'languages', 'rootNodes');
    }

    /**
     * @Route("/reorder/{id}/{direction}", name="demo_category_reorder", defaults={"direction" = "asc"})
     * @ParamConverter("root", class="GedmoDemoBundle:Category")
     */
    public function reorderAction(Category $root, $direction)
    {
        $repo = $this->get('doctrine.orm.entity_manager')
            ->getRepository('Gedmo\DemoBundle\Entity\Category')
        ;
        $direction = in_array($direction, array('asc', 'desc'), false) ? $direction : 'asc';
        $repo->reorder($root, 'title', $direction);
        return $this->redirect($this->generateUrl('demo_category_tree'));
    }

    /**
     * @Route("/move-up/{id}", name="demo_category_move_up")
     * @ParamConverter("node", class="GedmoDemoBundle:Category")
     */
    public function moveUpAction(Category $node)
    {
        $repo = $this->get('doctrine.orm.entity_manager')
            ->getRepository('Gedmo\DemoBundle\Entity\Category')
        ;

        $repo->moveUp($node);
        return $this->redirect($this->generateUrl('demo_category_tree'));
    }

    /**
     * @Route("/move-down/{id}", name="demo_category_move_down")
     * @ParamConverter("node", class="GedmoDemoBundle:Category")
     */
    public function moveDownAction(Category $node)
    {
        $repo = $this->get('doctrine.orm.entity_manager')
            ->getRepository('Gedmo\DemoBundle\Entity\Category')
        ;

        $repo->moveDown($node);
        return $this->redirect($this->generateUrl('demo_category_tree'));
    }

    /**
     * @Route("/show/{slug}", name="demo_category_show")
     * @Template
     */
    public function showAction($slug)
    {
        $em = $this->get('doctrine.orm.entity_manager');
        $dql = <<<____SQL
            SELECT c
            FROM GedmoDemoBundle:Category c
            WHERE c.slug = :slug
____SQL;
        $node = $em
            ->createQuery($dql)
            ->setMaxResults(1)
            ->setParameters(compact('slug'))
            ->setHint(
                Query::HINT_CUSTOM_OUTPUT_WALKER,
                'Gedmo\\Translatable\\Query\\TreeWalker\\TranslationWalker'
            )
            ->setHint(
                \Gedmo\Translatable\TranslationListener::HINT_INNER_JOIN,
                $this->get('session')->get('gedmo.trans.inner_join', false)
            )
            ->getSingleResult()
        ;

        $translationRepo = $em->getRepository(
            'Stof\DoctrineExtensionsBundle\Entity\Translation'
        );
        $translations = $translationRepo->findTranslations($node);
        $pathQuery = $em
            ->getRepository('Gedmo\DemoBundle\Entity\Category')
            ->getPathQuery($node)
        ;
        $pathQuery->setHint(
            Query::HINT_CUSTOM_OUTPUT_WALKER,
            'Gedmo\\Translatable\\Query\\TreeWalker\\TranslationWalker'
        );
        $path = $pathQuery->getArrayResult();

        return compact('node', 'translations', 'path');
    }

    /**
     * @Route("/delete/{id}", name="demo_category_delete")
     * @ParamConverter("node", class="GedmoDemoBundle:Category")
     */
    public function deleteAction(Category $node)
    {
        $em = $this->get('doctrine.orm.entity_manager');
        $em->remove($node);
        $em->flush();
        $this->get('session')->setFlash('message', 'Category '.$node->getTitle().' was removed');

        return $this->redirect($this->generateUrl('demo_category_tree'));
    }

    /**
     * @Route("/edit/{id}", name="demo_category_edit")
     * @ParamConverter("node", class="GedmoDemoBundle:Category")
     * @Template
     * @Method({"GET", "POST"})
     */
    public function editAction(Category $node)
    {
        $em = $this->get('doctrine.orm.entity_manager');
        $form = $this->createForm(new CategoryType($node), $node);
        if ('POST' === $this->get('request')->getMethod()) {
            $form->bindRequest($this->get('request'), $node);
            if ($form->isValid()) {
                $em->persist($node);
                $em->flush();
                $this->get('session')->setFlash('message', 'Category was updated');
                return $this->redirect($this->generateUrl('demo_category_tree'));
            } else {
                $this->get('session')->setFlash('error', 'Fix the following errors');
            }
        }
        $form = $form->createView();
        return compact('form');
    }

    /**
     * @Route("/add", name="demo_category_add")
     * @Template
     */
    public function addAction()
    {
        $form = $this->createForm(new CategoryType, new Category)->createView();
        return compact('form');
    }

    /**
     * @Route("/save", name="demo_category_save")
     * @Method("POST")
     */
    public function saveAction()
    {
        $node = new Category;
        $form = $this->createForm(new CategoryType, $node);
        $form->bindRequest($this->get('request'), $node);
        if ($form->isValid()) {
            $em = $this->get('doctrine.orm.entity_manager');
            $em->persist($node);
            $em->flush();
            $this->get('session')->setFlash('message', 'Category was added');
            return $this->redirect($this->generateUrl('demo_category_list'));
        } else {
            $this->get('session')->setFlash('error', 'Fix the following errors');
        }
        $form = $form->createView();
        return $this->render('GedmoDemoBundle:Category:add.html.twig', compact('form'));
    }

    private function getLanguages()
    {
        return $this->get('doctrine.orm.entity_manager')
            ->getRepository('Gedmo\DemoBundle\Entity\Language')
            ->findAll()
        ;
    }
}