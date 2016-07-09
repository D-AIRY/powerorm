<?php

namespace powerorm\form;

use powerorm\Contributor;
use powerorm\exceptions\FormNotReadyException;
use powerorm\exceptions\KeyError;
use powerorm\exceptions\ValidationError;
use powerorm\Object;


/**
 * Class Form
 * @package powerorm\form
 * @since 1.0.0
 * @author Eddilbert Macharia (http://eddmash.com) <edd.cowan@gmail.com>
 */
class BaseForm extends Object
{
    const NON_FIELD_ERRORS = '_all_';

    /**
     * Indicates if the form is ready for use, if false, this indicates the form is in customization mode and cannot
     * be used for things like validation.using it when not ready causes inconsistencies in how the form works
     *
     * Call done() to signal your done customizing the form
     * @var bool
     */
    protected $ready=FALSE;

    public $auto_id = 'id_%s';
    public $initial = [];
    public $data = [];
    public $is_bound = FALSE;
    protected $fields = [];
    public $validation_rules=[];
    public $cleaned_data = [];

    public function __construct($data=[], $initial=[], $kwargs=[]){

        if(!empty($data)):
            $this->is_bound = TRUE;
        endif;

        $this->data = $data;

        if(empty($data)):
            $data = [];
        endif;

        if(empty($initial)):
            $initial = [];
        endif;

        $this->initial = array_change_key_case($initial, CASE_LOWER);

        // replace the default options with the ones passed in.
        foreach ($kwargs as $key=>$value) :
            $this->{$key} = $value;
        endforeach;

        // load some libraries early enough
        $this->ci_instance()->load->helper('url');
        $this->ci_instance()->load->helper('form');



        $this->init();
    }

    public function fields(){
    
    }

    public function custom(){

    }

    public function setup(){

        $this->fields();

        $this->custom();


        if($this->_is_multipart()):
            // load the upload library
            $this->ci_instance()->load->library('upload');
        endif;
    }

    /**
     * Signals other parts of the form that its is ready for use.
     * @since 1.1.0
     * @author Eddilbert Macharia (http://eddmash.com) <edd.cowan@gmail.com>
     */
    public function done(){
        $this->setup();
        $this->ready = TRUE;
        return $this;
    }


    /**
     * Creates an opening form tag with a base URL built from your config preferences.
     *
     * It will optionally let you add form attributes and hidden input fields, and will always add the accept-charset
     * attribute based on the charset value in your config file.
     *
     *<pre><code>assuming we are using the controller `user/signup` to server this form.
     * echo $form->open(); // goes back to the controller method that served this form.
     * <form method="post" accept-charset="utf-8" action="http://example.com/index.php/user/signup">
     *
     * echo form->open('email/send'); // goes to the base_url  plus the “email/send” URI segments
     * <form method="post" accept-charset="utf-8" action="http://example.com/index.php/email/send">
     * </code></pre>
     *
     * This method also detects if the form contains any upload fields a generate a multipart form if they are found.
     * it is also responsible for displaying the form help_text.
     *
     * <h4>Adding Attributes</h4>
     *
     * Attributes can be added by passing an associative array to the second parameter, like this:
     *
     * <pre><code>$attributes = array('class' => 'email', 'id' => 'myform');
     * echo form->open('email/send', $attributes);</code></pre>
     *
     * Alternatively, you can specify the second parameter as a string:
     *
     * <pre><code>echo form->open('email/send', 'class="email" id="myform"');</code></pre>
     *
     * The above examples would create a form similar to this:
     *
     * <pre><code>&lt; form method="post" accept-charset="utf-8"
     * action="http://example.com/index.php/email/send" class="email" id="myform" &gt;</code></pre>
     *
     * @param string $action
     * @param array $attributes
     * @param array $hidden
     * @return string
     *
     */
    public function open($kwargs=[]){
        assert(is_array($kwargs),
            "open() expects an associative array, options are { action, attributes, hidden, csrf }");

        $action = '';
        $attributes = array();
        $hidden = array();

        extract($kwargs);

        if(strlen($action)<=0):
            $action = current_url();
        endif;

        // create a multipart form or a normal form
        if($this->_is_multipart()):
            $form_open = form_open_multipart($action, $attributes, $hidden);
        else:
            $form_open = form_open($action, $attributes, $hidden);
        endif;

        if(isset($this->form_message)):
            $form_open .= "<p class='help-block form-help-text'>$this->form_message</p>";
        endif;

        return $form_open;
    }


    /**
     * Create the form closing tags and displays any errors that have not been display explicitly.
     *
     * <pre><code>echo form_close($string);</code></pre>
     *
     *
     * Would produce:
     * <pre><code> &lt;/form &gt; </code></pre>
     *
     * @param string $extra
     * @return string
     */
    public function close($extra = ''){

        return form_close($extra);
    }

    /**
     * Creates a form fieldset.
     * @param $legend_text
     * @param array $attrs
     * @return string
     */
    public function open_fieldset($legend_text, $attrs=array()){
        return form_fieldset($legend_text, $attrs);
    }

    /**
     * Closes a form fieldset
     * @param string $extra
     * @return string
     */
    public function close_fieldset($extra=''){
        return form_fieldset_close($extra);
    }


    /**
     * Returns true if the form is bound and its has not errors after validation has been run.
     * @return bool
     * @since 1.1.0
     * @author Eddilbert Macharia (http://eddmash.com) <edd.cowan@gmail.com>
     */
    public function is_valid(){
        $this->_is_ready(__METHOD__);
        return $this->is_bound && $this->_form_has_errors();
    }


    /**
     * return a list of errors related to the form and its fields
     * @return mixed
     * @since 1.1.0
     * @author Eddilbert Macharia (http://eddmash.com) <edd.cowan@gmail.com>
     */
    public function errors(){

        if(empty($this->_errors)):
            $this->full_clean();
        endif;

        return $this->_errors;
    }

    /**
     * Raise error if form is not ready for use.
     * @param $method
     * @throws FormNotReadyException
     * @since 1.1.0
     * @author Eddilbert Macharia (http://eddmash.com) <edd.cowan@gmail.com>
     */
    public function _is_ready($method){
        if(!$this->ready):
            throw new FormNotReadyException(
                sprintf('Please ensure you have called done() method of the form before is { %s }', $method));
        endif;
    }


    /**
     * Returns true of form is ready for use or false if its still in customizaiton mode.
     * @return bool
     * @since 1.1.0
     * @author Eddilbert Macharia (http://eddmash.com) <edd.cowan@gmail.com>
     */
    public function is_ready(){
        return $this->ready;
    }



    /**
     * Clean the form and the fields i.e. do the validations for this form and its fields
     * @return null
     * @since 1.1.0
     * @author Eddilbert Macharia (http://eddmash.com) <edd.cowan@gmail.com>
     */
    public function full_clean(){

        $this->_errors = [];

        if(!$this->is_bound):
            return NULL;
        endif;

        // this part is for using the CI_VALIDATION
        $this->ci_validation();

        // a better way of doing validation, easy for users to extend
        $this->_clean_fields();
        $this->_clean_form();
    }

    public function ci_validation(){
        $this->validator()->set_rules($this->validation_rules);
        $this->validator()->set_data($this->data);

        if($this->validator()->run() === FALSE):
            $this->_errors = $this->validator()->error_array();
        endif;
    }

    /**
     * Gets a single field instance in the form fields array and returns it
     *
     * <h4>Usage</h4>
     *
     * if a form has a fields username, you get the field object:
     *
     * <pre><code>$form->get_field('username);</code></pre>
     *
     * @param $field_name
     * @return mixed
     * @throws KeyError
     * @since 1.0.0
     * @author Eddilbert Macharia (http://eddmash.com) <edd.cowan@gmail.com>
     */
    public function get_field($field_name){

        if((array_key_exists($this->lower_case($field_name), $this->fields))):
            return $this->fields[$this->lower_case($field_name)] ;
        endif;

        throw new KeyError(sprintf('Field %1$s not found in %2$s', $field_name, $this->get_class_name()));
    }

    /**
     * used to set up field on the form, usually used by a fields contribute method
     * @param $field
     * @since 1.1.0
     * @author Eddilbert Macharia (http://eddmash.com) <edd.cowan@gmail.com>
     */
    public function load_field($field){
        $this->fields[$field->name]=$field;
    }

    /**
     * Sets up so validation rules to be used by the CI_VALIDATION
     * @since 1.1.0
     * @author Eddilbert Macharia (http://eddmash.com) <edd.cowan@gmail.com>
     */
    public function field_validation_rules($rules){
        $this->validation_rules[] = $rules;
    }

    public function clean(){
        return $this->cleaned_data;
    }

    public function add_error($name, $error){

        // for consistency convert them to a validation error object
        if(!$error instanceof ValidationError):
            $error = new ValidationError($error);
        endif;

        if(!$name):
            // todo store non field errors as arrays, current can only store one non field per form
            $name = self::NON_FIELD_ERRORS;
        endif;

        // todo deal with a list of validation errors

        $this->_errors[$name] = $error->get_message();
    }

    public function add_field($name, $field)
    {
        $this->_field_setup($name, $field);
    }

    public function non_field_errors(){
        if(array_key_exists(self::NON_FIELD_ERRORS, $this->errors())):
            return $this->errors()[self::NON_FIELD_ERRORS];
        endif;

        return [];
    }

    public function hidden_fields(){

        $hidden_fields = [];
        foreach ($this->fields as $name=>$field) :
            if($field->is_hidden()):
                $hidden_fields[$name] = $field;
            endif;
        endforeach;

        return $hidden_fields;

    }

    public function visible_fields(){

        $visible_fields = [];
        foreach ($this->fields as $name=>$field) :
            if(!$field->is_hidden()):
                $visible_fields[$name] = $field;
            endif;
        endforeach;

        return $visible_fields;

    }

    /**
     * @return string
     * @since 1.1.0
     * @author Eddilbert Macharia (http://eddmash.com) <edd.cowan@gmail.com>
     */
    public function as_p(){
        return $this->_html_output([
            'row'=>'<p>%1$s <br> %2$s <br> %3$s</p>',
        ]);
    }

    public function validator(){

        if(empty($this->validator)):
            $this->ci_instance()->load->library('form_validation');
            $this->validator = $this->ci_instance()->form_validation;
        endif;

        return $this->validator;
    }





    public function _clean_fields(){
        // who survived the CI_validator?
        $this->cleaned_data = array_diff_key($this->data, $this->_errors);

        foreach ($this->fields as $name=>$field) :

            // if field has failed validation, no need to go on
            if(array_key_exists($name, $this->_errors)):
                continue;
            endif;

            if($field->disabled):
                $value = array_key_exists($name, $this->initial) ? $this->initial[$name]: $field->initial;
            else:
                if(array_key_exists($name, $this->cleaned_data)):

                    $value = $field->widget->value_from_data_collection($this->cleaned_data, $name);
                else:
                    $value = $field->data();
                endif;
            endif;

            try{
                // run default field validations
                $field->clean($value);

                // just in case,  confirm the field has not field validation already
                if(!array_key_exists($name, $this->_errors)):
                    $this->cleaned_data[$name] = $value;
                endif;

                // run custom validation by user
                $field_clean_method = sprintf('clean_%s', $name);
                if($this->has_method($field_clean_method)):
                    $value = call_user_func([$this, $field_clean_method]);
                    $this->cleaned_data[$name] = $value;
                endif;

            }catch (ValidationError $e){

                $this->add_error($name, $e);

                if(array_key_exists($name, $this->cleaned_data)):
                    unset($this->cleaned_data[$name]);
                endif;
            }

        endforeach;

    }
    
    public function _clean_form(){
        try{
            $clean_data = $this->clean();
        }catch (ValidationError $e){
            $clean_data = NULL;
            $this->add_error(NULL, $e);
        }

        if($clean_data):
            $this->cleaned_data = $clean_data;
        endif;
    }

    public function _form_has_errors(){
        return empty($this->errors());
    }

    protected function _is_multipart()
    {
        if(empty($this->fields)):
            return FALSE;
        endif;

        foreach ($this->fields as $field) :
            if($field->widget->needs_multipart_form):
                return TRUE;
            endif;
        endforeach;
    }

    /**
     * @return string
     * @since 1.0.0
     * @author Eddilbert Macharia (http://eddmash.com) <edd.cowan@gmail.com>
     */
    protected function _html_output($opts = []){
        //todo display errros
        $top_errors = $this->non_field_errors();
        $row = '';
        extract($opts);

        $output = [];
        $hidden_output = [];

        foreach ($this->fields as $name=>$field) :
            if($field->is_hidden()):
                $hidden_output[] = (string)$field;
            else:
                $output[] = sprintf($row, $field->label(), $field, $field->help_text);
            endif;
        endforeach;

        // add errors to the top


        // add hidden inputs to end
        $output = array_merge($output, $hidden_output);
        return join(' ', $output);
    }

    protected function _field_setup($name, $value)
    {
        if($value instanceof Contributor):
            $value->contribute_to_class($name, $this);
        else:
            $this->{$name} = $value;
        endif;
    }

    /**
     * @ignore
     * @param $field_name
     * @return mixed
     * @throws KeyError
     * @since 1.0.0
     * @author Eddilbert Macharia (http://eddmash.com) <edd.cowan@gmail.com>
     */
    public function __get($field_name){
        $this->setup();
        if(array_key_exists($field_name, $this->fields)):
            return $this->get_field($field_name)->value;
        endif;
    }

    public function __set($name, $value){
        $this->_field_setup($name, $value);
    }

    public function __toString(){
        $this->setup();

        return $this->as_p();
    }
}