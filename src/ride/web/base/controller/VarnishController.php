<?php

namespace ride\web\base\controller;

use ride\library\http\Response;
use ride\library\validation\exception\ValidationException;
use ride\library\varnish\exception\VarnishException;
use ride\library\varnish\VarnishAdmin;
use ride\library\varnish\VarnishPool;

/**
 * Controller for the Varnish backend
 */
class VarnishController extends AbstractController {

    /**
     * Instance of the varnish pool which is being managed
     * @var \ride\library\varnish\VarnishPool
     */
    protected $varnishPool;

    /**
     * Constructs a new instance
     * @param \ride\library\varnish\VarnishPool $varnishPool
     * @return null
     */
    public function __construct(VarnishPool $varnishPool) {
        $this->varnishPool = $varnishPool;
    }

    /**
     * Action for the overview of the servers
     * @return null
     */
    public function indexAction() {
        $translator = $this->getTranslator();

        $form = $this->createFormBuilder();
        $form->setId('form-varnish');
        $form->addRow('url', 'string', array(
            'label' => $translator->translate('label.url.or.expression'),
            'attributes' => array(
                'placeholder' => $translator->translate('label.url.or.expression'),
            ),
            'validators' => array(
                'required' => array(),
            ),
        ));
        $form->addRow('force', 'option', array(
            'label' => $translator->translate('label.force'),
        ));
        $form->build();

        if ($form->isSubmitted()) {
            try {
                $form->validate();

                $data = $form->getData();

                if ($data['force']) {
                    $this->varnishPool->setIgnoreOnFail(true);
                }

                if (substr($data['url'], -1) === '*') {
                    $data['url'] = substr($data['url'], 0, -1);

                    $recursive = true;
                } else {
                    $recursive = false;
                }

                if (strpos($data['url'], ' ') === false && strpos($data['url'], 'http') !== 0) {
                    // normalize to url when not an expression
                    $data['url'] = 'http://' . $data['url'];
                }

                if (filter_var($data['url'], FILTER_VALIDATE_URL, FILTER_FLAG_SCHEME_REQUIRED) !== false) {
                    $this->varnishPool->banUrl($data['url'], $recursive);
                } else {
                    $this->varnishPool->ban($data['url']);
                }

                $this->addSuccess('success.varnish.ban');
                $this->response->setRedirect($this->request->getUrl());

                return;
            } catch (ValidationException $exception) {
                $this->setValidationException($exception, $form);
            } catch (VarnishException $exception) {
                $this->response->setStatusCode(Response::STATUS_CODE_UNPROCESSABLE_ENTITY);

                $this->addError('error.varnish', array('error' => $exception->getMessage()));
            }
        }

        $servers = $this->varnishPool->getServers();

        ksort($servers);

        $this->setTemplateView('varnish/index', array(
            'form' => $form->getView(),
            'servers' => $servers,
        ));
    }

    /**
     * Action for the form of a server
     * @return null
     */
    public function formAction($server = null) {
        if ($server) {
            $server = $this->varnishPool->getServer($server);
            if (!$server) {
                $this->response->setNotFound();

                return;
            }

            $data = array(
                'host' => $server->getHost(),
                'port' => $server->getPort(),
                'secret' => $server->getSecret(),
            );
        } else {
            $data = null;
        }

        $referer = $this->getReferer();
        $translator = $this->getTranslator();

        $form = $this->createFormBuilder($data);
        $form->addRow('host', 'string', array(
            'label' => $translator->translate('label.server'),
            'validators' => array(
                'required' => array(),
            ),
        ));
        $form->addRow('port', 'string', array(
            'label' => $translator->translate('label.port'),
            'default' => 6082,
            'validators' => array(
                'required' => array(),
            ),
        ));
        $form->addRow('secret', 'string', array(
            'label' => $translator->translate('label.secret'),
        ));
        $form->build();

        if ($form->isSubmitted()) {
            try {
                $form->validate();

                $data = $form->getData();
                if ($data['secret'] === '') {
                    $data['secret'] = null;
                }

                $data = new VarnishAdmin($data['host'], $data['port'], $data['secret']);

                if ($server) {
                    $this->varnishPool->removeServer((string) $server);
                }
                $this->varnishPool->addServer($data);

                if (!$referer) {
                    $referer = $this->getUrl('varnish');
                }

                $this->response->setRedirect($referer);

                return;
            } catch (ValidationException $exception) {
                $this->setValidationException($exception, $form);
            }
        }

        $this->setTemplateView('varnish/server', array(
            'form' => $form->getView(),
            'server' => $server,
            'referer' => $referer,
        ));
    }

    /**
     * Action for the delete of a server from the pool
     * @return null
     */
    public function deleteAction($server) {
        $server = $this->varnishPool->getServer($server);
        if (!$server) {
            $this->response->setNotFound();

            return;
        }

        $this->varnishPool->removeServer((string) $server);

        $this->response->setRedirect($this->getUrl('varnish'));
    }

}
